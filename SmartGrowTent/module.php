<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';
require_once __DIR__ . '/../libs/Trait_DeviceRegistration.php';

/**
 * SmartGrowTent - IP-Symcon 9 Modul zur Automatisierung eines Cannabis Grow-Zelts.
 * 
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/
 */
class SmartGrowTent extends IPSModuleStrict
{
    use DeviceRegistration_Trait;
    use SmartLog_Trait;
    use DeviceAvailability_Trait;

    public function Create(): void
    {
        parent::Create();

        $this->DA_RegisterAvailability(900);

        // Registrierung der Properties
        
        $this->RegisterPropertyInteger('PumpWaterID', 0);
        $this->RegisterPropertyInteger('PumpNutrientID', 0);
        $this->RegisterPropertyInteger('PumpPHDownID', 0);
        
        $this->RegisterPropertyInteger('LightSwitchID', 0);
        $this->RegisterPropertyInteger('LightPowerID', 0);
        $this->RegisterPropertyInteger('FanSwitchID', 0);
        $this->RegisterPropertyInteger('FanPowerID', 0);
        
        for ($i = 1; $i <= 3; $i++) {
            $this->RegisterPropertyString("Plant{$i}Name", "Pflanze $i");
            $this->RegisterPropertyInteger("Plant{$i}MoistureID", 0);
            $this->RegisterPropertyInteger("Plant{$i}ECID", 0);
        }
        
        $this->RegisterPropertyInteger('SphereTempID', 0);
        $this->RegisterPropertyInteger('SphereHumidityID', 0);
        $this->RegisterPropertyInteger('SphereLightID', 0);
        
        $this->RegisterPropertyInteger('LeakSensorID', 0);
        
        $this->RegisterPropertyInteger('MaxWaterSec', 1800);
        $this->RegisterPropertyInteger('MaxNutrientSec', 60);
        $this->RegisterPropertyFloat('MaxNutrientDailyML', 50.0);
        
        $this->RegisterPropertyInteger('MinWaterWaitMin', 60);
        $this->RegisterPropertyInteger('MinNutrientWaitMin', 30);
        
        $this->RegisterPropertyFloat('PumpWater30SecML', 250.0);
        $this->RegisterPropertyFloat('PumpNutrient30SecML', 35.0);
        $this->RegisterPropertyFloat('PumpWaterMLPerSec', 8.33);
        $this->RegisterPropertyFloat('PumpNutrientMLPerSec', 1.16);
        
        $this->RegisterPropertyString('GrowthPhase', 'vegetative');
        $this->RegisterPropertyInteger('WeekNumber', 1);
        $this->RegisterPropertyBoolean('FlushPhase', false);
        
        $this->RegisterPropertyInteger('AutomationInterval', 30);
        $this->RegisterPropertyString('GeminiApiKey', '');

        // Variablen Registrierung
        $this->RegisterVariableFloat('VPD', 'Aktueller VPD', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 10);
        $this->RegisterVariableString('Health', 'Gesundheitsstatus', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'leaf',
        ], 20);
        $this->RegisterVariableFloat('DailyNutrientML', 'Tages-Dünger (ml)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 30);
        $this->RegisterVariableInteger('LastWatering', 'Letzte Bewässerung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
        ], 40);
        $this->RegisterVariableInteger('LastNutrient', 'Letzte Düngung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
        ], 50);
        $this->RegisterVariableString('LastGeminiResponse', 'Letzte KI-Antwort', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'robot',
        ], 60);
        $this->RegisterVariableBoolean('SystemActive', 'System aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'power-off',
        ], 70);
        
        // RequestAction für SystemActive erlauben
        $this->EnableAction('SystemActive');

        // Timer registrieren
        $this->RegisterTimer('AutomationCycle', 0, 'SGT_RunAutomation($_IPS[\'TARGET\']);');
        $this->RegisterTimer('PumpWaterOff', 0, 'SGT_StopWaterPump($_IPS[\'TARGET\']);');
        $this->RegisterTimer('PumpNutrientOff', 0, 'SGT_StopNutrientPump($_IPS[\'TARGET\']);');
        $this->RegisterTimer('PumpCalibrationOff', 0, 'SGT_StopCalibration($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        
        $this->DA_ApplyPresentation();

        // Validierung der Konfiguration
        $apiKey = $this->ReadPropertyString('GeminiApiKey');
        if (empty($apiKey)) {
            $this->SetStatus(204); // API Key fehlt
            return;
        }

        $waterPump = $this->ReadPropertyInteger('PumpWaterID');
        $nutrientPump = $this->ReadPropertyInteger('PumpNutrientID');
        if ($waterPump === 0 && $nutrientPump === 0) {
            $this->SetStatus(201); // Keine Pumpe konfiguriert
            return;
        }

        $sphereTemp = $this->ReadPropertyInteger('SphereTempID');
        $plant1Moist = $this->ReadPropertyInteger('Plant1MoistureID');
        if ($sphereTemp === 0 && $plant1Moist === 0) {
            $this->SetStatus(202); // Kein Sensor konfiguriert
            return;
        }

        // Modul ist aktiv
        $this->SetStatus(102);

        $leakSensorID = $this->ReadPropertyInteger('LeakSensorID');
        if ($leakSensorID !== 0) {
            $this->RegisterMessage($leakSensorID, 10603);
        }

        // Timer setzen
        $interval = $this->ReadPropertyInteger('AutomationInterval');
        if ($interval > 0 && $this->GetValue('SystemActive')) {
            $this->SetTimerInterval('AutomationCycle', $interval * 60 * 1000);
        } else {
            $this->SetTimerInterval('AutomationCycle', 0);
        }
    
        $this->DR_Register('DevicesGenericSensor', [
            'Reachable_VarID' => $this->GetIDForIdent('DeviceAvailable'),
        ]);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === 10603 && $SenderID === $this->ReadPropertyInteger('LeakSensorID') && $Data[0] === true) {
            $this->EmergencyStop();
            $this->SetStatus(203);
            $this->SLogError('LECKAGE ERKANNT! Notstopp ausgeführt.');
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'SystemActive':
                $this->SetValue($Ident, $Value);
                if ($Value) {
                    $interval = $this->ReadPropertyInteger('AutomationInterval');
                    $this->SetTimerInterval('AutomationCycle', $interval * 60 * 1000);
                    $this->SLogInfo('System aktiviert.');
                } else {
                    $this->SetTimerInterval('AutomationCycle', 0);
                    $this->EmergencyStop();
                    $this->SLogError('System deaktiviert. Notstopp ausgeführt.');
                }
                break;
            default:
                throw new Exception("Invalid ident");
        }
    }

    /**
     * Hauptzyklus der Automatisierung
     */
    public function RunAutomation(): void
    {
        if (!$this->GetValue('SystemActive')) {
            $this->SendDebug('RunAutomation', 'System ist inaktiv, überspringe Zyklus.', 0);
            return;
        }

        $this->resetDailyCounters();

        $sensorData = $this->collectSensorData();
        
        // Plausibilitätsprüfung (Fehlerzähler)
        $anomalies = 0;
        foreach (['Plant1', 'Plant2', 'Plant3'] as $plant) {
            if (isset($sensorData[$plant]['moisture']) && ($sensorData[$plant]['moisture'] < 0 || $sensorData[$plant]['moisture'] > 100)) $anomalies++;
            if (isset($sensorData[$plant]['ec']) && ($sensorData[$plant]['ec'] < 0 || $sensorData[$plant]['ec'] > 5)) $anomalies++;
        }
        if (isset($sensorData['sphere']['temp']) && ($sensorData['sphere']['temp'] < 5 || $sensorData['sphere']['temp'] > 50)) $anomalies++;
        if (isset($sensorData['sphere']['humidity']) && ($sensorData['sphere']['humidity'] < 5 || $sensorData['sphere']['humidity'] > 100)) $anomalies++;

        if ($anomalies > 2) {
            $this->DA_SetAvailable(false, "Zu viele Sensor-Anomalien");
            $this->EmergencyStop();
            $this->SLogError("Zu viele Sensor-Anomalien ($anomalies). Notstopp ausgeführt.");
            return;
        }
        
        $this->DA_SetAvailable(true);

        // VPD berechnen
        $vpd = 0.0;
        if (isset($sensorData['sphere']['temp']) && isset($sensorData['sphere']['humidity'])) {
            $vpd = $this->calcVPD((float)$sensorData['sphere']['temp'], (float)$sensorData['sphere']['humidity']);
            $this->SetValue('VPD', $vpd);
        }

        $sensorData['calculated_vpd'] = $vpd;

        $prompt = $this->buildGeminiPrompt($sensorData);
        $this->SendDebug('Gemini Prompt', $prompt, 0);

        $response = $this->askGemini($prompt);
        if ($response === null) {
            $this->SLogError('Fehler bei der Kommunikation mit Gemini.');
            return;
        }

        $this->SetValue('LastGeminiResponse', json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->SendDebug('Gemini Response', json_encode($response), 0);

        // Entscheidungen verarbeiten
        if (isset($response['assessment']['health'])) {
            $this->SetValue('Health', (string)$response['assessment']['health']);
        }

        $isLightOn = isset($sensorData['equipment']['light_switch']) ? $sensorData['equipment']['light_switch'] : false;

        // Wasser-Pumpe
        if (isset($response['water']) && $response['water']['activate'] === true) {
            $durationSec = (int)$response['water']['duration_sec'];
            if ($this->validatePumpOperation('water', $durationSec, $isLightOn) && $durationSec > 0) {
                $pumpID = $this->ReadPropertyInteger('PumpWaterID');
                if ($pumpID !== 0) {
                    $this->SLogInfo("Starte Bewässerung für $durationSec Sekunden. Grund: " . ($response['water']['reason'] ?? ''));
                    if (!$this->setPumpLevel($pumpID, 1.0, 'Wasserpumpe')) {
                        return;
                    }
                    $this->SetTimerInterval('PumpWaterOff', $durationSec * 1000);
                    $this->SetValue('LastWatering', time());
                }
            }
        }

        // Dünger-Pumpe
        if (isset($response['nutrient']) && $response['nutrient']['activate'] === true) {
            $durationSec = (int)$response['nutrient']['duration_sec'];
            $estimatedML = (float)($response['nutrient']['estimated_ml'] ?? 0);
            if ($this->validatePumpOperation('nutrient', $durationSec, $isLightOn, $estimatedML) && $durationSec > 0) {
                $pumpID = $this->ReadPropertyInteger('PumpNutrientID');
                if ($pumpID !== 0) {
                    $dailyNutrient = $this->GetValue('DailyNutrientML');
                    $this->SLogInfo("Gebe Dünger für $durationSec Sekunden ($estimatedML ml). Grund: " . ($response['nutrient']['reason'] ?? ''));
                    if (!$this->setPumpLevel($pumpID, 1.0, 'Düngerpumpe')) {
                        return;
                    }
                    $this->SetTimerInterval('PumpNutrientOff', $durationSec * 1000);
                    $this->SetValue('LastNutrient', time());
                    $this->SetValue('DailyNutrientML', $dailyNutrient + $estimatedML);
                }
            }
        }

        // Lüftersteuerung
        if (isset($response['fan']) && isset($response['fan']['should_be_on'])) {
            $fanSwitchID = $this->ReadPropertyInteger('FanSwitchID');
            if ($fanSwitchID !== 0) {
                $shouldBeOn = (bool)$response['fan']['should_be_on'];
                try {
                    $isFanOn = GetValueBoolean($fanSwitchID);
                } catch (Exception $e) {
                    $this->SLogWarning('Lüfter-Status konnte nicht gelesen werden: ' . $e->getMessage());
                    $isFanOn = !$shouldBeOn; // Erzwinge Schaltversuch
                }
                if ($shouldBeOn !== $isFanOn) {
                    $this->SLogInfo("Schalte Lüfter " . ($shouldBeOn ? "EIN" : "AUS") . ". Grund: " . ($response['fan']['reason'] ?? ''));
                    try {
                        RequestAction($fanSwitchID, $shouldBeOn);
                    } catch (Exception $e) {
                        $this->SLogWarning('Lüfter-Befehl fehlgeschlagen: ' . $e->getMessage());
                    }
                }
            }
        }
        
        // Dynamisches Intervall anpassen
        if (isset($response['next_check_minutes'])) {
            $nextCheck = (int)$response['next_check_minutes'];
            if ($nextCheck >= 5 && $nextCheck <= 120) {
                $this->SetTimerInterval('AutomationCycle', $nextCheck * 60 * 1000);
            }
        }
    }

    /**
     * Stoppt die Wasserpumpe
     */
    public function StopWaterPump(): void
    {
        $this->SetTimerInterval('PumpWaterOff', 0);
        $pumpID = $this->ReadPropertyInteger('PumpWaterID');
        $this->setPumpLevel($pumpID, 0.0, 'Bewässerung planmäßig gestoppt');
    }

    /**
     * Stoppt die Düngerpumpe
     */
    public function StopNutrientPump(): void
    {
        $this->SetTimerInterval('PumpNutrientOff', 0);
        $pumpID = $this->ReadPropertyInteger('PumpNutrientID');
        $this->setPumpLevel($pumpID, 0.0, 'Düngung planmäßig gestoppt');
    }

    /**
     * Notstopp aller Systeme
     */
    public function EmergencyStop(): void
    {
        $this->SetValue('Health', 'NOTFALL');
        $this->SetValue('SystemActive', false);
        $this->SetTimerInterval('AutomationCycle', 0);
        $this->SetTimerInterval('PumpWaterOff', 0);
        $this->SetTimerInterval('PumpNutrientOff', 0);

        $this->setPumpLevel($this->ReadPropertyInteger('PumpWaterID'), 0.0, 'Notstopp Wasserpumpe');
        $this->setPumpLevel($this->ReadPropertyInteger('PumpNutrientID'), 0.0, 'Notstopp Düngerpumpe');
        $this->setPumpLevel($this->ReadPropertyInteger('PumpPHDownID'), 0.0, 'Notstopp pH-Down-Pumpe');

        $this->SLogError('NOTSTOPP ALLER PUMPEN DURCHGEFÜHRT!');
    }

    /**
     * Kalibriert eine Pumpe
     */
    public function CalibratePump(string $type, int $seconds): string
    {
        if ($seconds < 1 || $seconds > 120) {
            return "Ungültige Dauer für Kalibrierung (1-120s erlaubt).";
        }

        $pumpID = 0;
        if ($type === 'water') {
            $pumpID = $this->ReadPropertyInteger('PumpWaterID');
        } elseif ($type === 'nutrient') {
            $pumpID = $this->ReadPropertyInteger('PumpNutrientID');
        } else {
            return "Ungültiger Pumpentyp.";
        }

        if ($pumpID === 0) {
            return "Pumpe $type ist nicht konfiguriert.";
        }

        if (!$this->setPumpLevel($pumpID, 1.0, "Kalibrierung $type")) {
            return "Fehler: Pumpe $type konnte nicht aktiviert werden.";
        }
        $this->SetTimerInterval('PumpCalibrationOff', $seconds * 1000);

        return "Pumpe $type für $seconds Sekunden aktiviert. Bitte messen Sie das Volumen.";
    }

    /**
     * Stoppt die Kalibrierung
     */
    public function StopCalibration(): void
    {
        $this->SetTimerInterval('PumpCalibrationOff', 0);
        
        $this->setPumpLevel($this->ReadPropertyInteger('PumpWaterID'), 0.0, 'Kalibrierung gestoppt (Wasser)');
        $this->setPumpLevel($this->ReadPropertyInteger('PumpNutrientID'), 0.0, 'Kalibrierung gestoppt (Dünger)');
    }

    /**
     * Berechnet den VPD (Vapor Pressure Deficit) in kPa
     */
    private function calcVPD(float $tempC, float $humidity, float $leafOffset = 2.0): float
    {
        // Blatttemperatur annähernd berechnen
        $tLeaf = $tempC - $leafOffset;
        
        // Sättigungsdampfdruck des Blattes (SVP_leaf)
        $svpLeaf = 0.6108 * exp((17.27 * $tLeaf) / ($tLeaf + 237.3));
        
        // Sättigungsdampfdruck der Luft (SVP_air)
        $svpAir = 0.6108 * exp((17.27 * $tempC) / ($tempC + 237.3));
        
        // Tatsächlicher Dampfdruck der Luft (AVP)
        $avp = $svpAir * ($humidity / 100.0);
        
        $vpd = $svpLeaf - $avp;
        return max(0.0, round($vpd, 2));
    }

    /**
     * Kommunikation mit der Google Gemini API
     */
    private function askGemini(string $prompt): ?array
    {
        $apiKey = $this->ReadPropertyString('GeminiApiKey');
        if (empty($apiKey)) {
            $this->SLogError('Gemini API', 'Kein API-Schlüssel hinterlegt');
            return null;
        }
        
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;
        
        $data = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json'
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->SLogError('Gemini API Fehler', "HTTP $httpCode: $response");
            return null;
        }

        $decoded = json_decode($response, true);
        $jsonText = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($jsonText)) {
            return null;
        }
        
        $result = json_decode($jsonText, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SLogError('Gemini JSON-Fehler', json_last_error_msg());
            return null;
        }
        
        return $result;
    }

    /**
     * Sammelt alle Sensor- und Statusdaten
     */
    private function collectSensorData(): array
    {
        $data = ['equipment' => [], 'sphere' => []];

        // Pflanzen Sensoren
        for ($i = 1; $i <= 3; $i++) {
            $name = $this->ReadPropertyString("Plant{$i}Name");
            $moistID = $this->ReadPropertyInteger("Plant{$i}MoistureID");
            $ecID = $this->ReadPropertyInteger("Plant{$i}ECID");
            
            if ($moistID !== 0 || $ecID !== 0) {
                $data["Plant{$i}"] = [
                    'name' => $name,
                    'moisture' => $moistID !== 0 ? $this->safeGetFloat($moistID, "Plant{$i}Moisture") : null,
                    'ec' => $ecID !== 0 ? $this->safeGetFloat($ecID, "Plant{$i}EC") : null
                ];
            }
        }

        // Sphere Sensoren
        $tempID = $this->ReadPropertyInteger('SphereTempID');
        if ($tempID !== 0) $data['sphere']['temp'] = $this->safeGetFloat($tempID, 'SphereTemp');
        
        $humID = $this->ReadPropertyInteger('SphereHumidityID');
        if ($humID !== 0) $data['sphere']['humidity'] = $this->safeGetFloat($humID, 'SphereHumidity');
        
        $lightID = $this->ReadPropertyInteger('SphereLightID');
        if ($lightID !== 0) $data['sphere']['light_ppfd'] = $this->safeGetFloat($lightID, 'SphereLight');

        // Equipment
        $lightSwitchID = $this->ReadPropertyInteger('LightSwitchID');
        if ($lightSwitchID !== 0) $data['equipment']['light_switch'] = $this->safeGetBool($lightSwitchID, 'LightSwitch');
        
        $lightPowerID = $this->ReadPropertyInteger('LightPowerID');
        if ($lightPowerID !== 0) $data['equipment']['light_power_w'] = $this->safeGetFloat($lightPowerID, 'LightPower');
        
        $fanSwitchID = $this->ReadPropertyInteger('FanSwitchID');
        if ($fanSwitchID !== 0) $data['equipment']['fan_switch'] = $this->safeGetBool($fanSwitchID, 'FanSwitch');
        
        $fanPowerID = $this->ReadPropertyInteger('FanPowerID');
        if ($fanPowerID !== 0) $data['equipment']['fan_power_w'] = $this->safeGetFloat($fanPowerID, 'FanPower');

        // Historische Daten
        $data['tracking'] = [
            'last_watering_unix' => $this->GetValue('LastWatering'),
            'last_nutrient_unix' => $this->GetValue('LastNutrient'),
            'daily_nutrient_ml' => $this->GetValue('DailyNutrientML')
        ];

        return $data;
    }

    /**
     * Gibt den Förderstrom einer Pumpe in ml/Sekunde zurück.
     * Nutzt bevorzugt PumpWater30SecML / PumpNutrient30SecML (geteilt durch 30).
     */
    private function getPumpMLPerSec(string $type): float
    {
        $prop30 = $type === 'water' ? 'PumpWater30SecML' : 'PumpNutrient30SecML';
        $propPerSec = $type === 'water' ? 'PumpWaterMLPerSec' : 'PumpNutrientMLPerSec';

        $val30 = $this->ReadPropertyFloat($prop30);
        if ($val30 > 0) {
            return round($val30 / 30.0, 3);
        }

        $valSec = $this->ReadPropertyFloat($propPerSec);
        return $valSec > 0 ? $valSec : ($type === 'water' ? 8.33 : 1.16);
    }

    /**
     * Baut den Prompt für Gemini
     */
    private function buildGeminiPrompt(array $sensorData): string
    {
        $phase = $this->ReadPropertyString('GrowthPhase');
        $week = $this->ReadPropertyInteger('WeekNumber');
        $flush = $this->ReadPropertyBoolean('FlushPhase') ? 'JA' : 'NEIN';
        
        $waterPumpML = $this->getPumpMLPerSec('water');
        $nutrientPumpML = $this->getPumpMLPerSec('nutrient');
        $maxDailyML = $this->ReadPropertyFloat('MaxNutrientDailyML');

        $jsonData = json_encode($sensorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return "Du bist eine KI für die Automatisierung eines Cannabis Grow-Zelts. Analysiere die Sensordaten und triff Entscheidungen.

Systemkonfiguration:
- Anbau: Cannabis in Erde mit mineralischem Dünger.
- Phase: {$phase} (Woche {$week})
- Flush-Phase aktiv: {$flush}
- Pumpe Wasser: {$waterPumpML} ml/sec
- Pumpe Dünger: {$nutrientPumpML} ml/sec
- Max. Dünger pro Tag: {$maxDailyML} ml

Regeln (WICHTIG):
1. DRYBACK: Die Erde muss zwischen Bewässerungen antrocknen. Nicht wässern, wenn Feuchtigkeit > 60%. Ideal: 30-40% vor dem nächsten Gießen.
2. NACHTRUHE: Niemals bewässern oder düngen, wenn das Licht aus ist (light_switch = false), um Wurzelfäule zu vermeiden.
3. DOSE-AND-WAIT: Wähle kleine Gießmengen und warte, statt zu übersättigen.
4. FLUSHING: Wenn Flush-Phase aktiv ist (JA), DARF KEIN DÜNGER gegeben werden.
5. VPD Ziele (kPa): Seedling 0.4-0.8, Vegetativ 0.8-1.2, Blüte 1.0-1.5
6. EC Ziele (mS/cm): Seedling 0.4-0.8, Veggie 0.8-1.8, Blüte 1.3-2.2, Flush 0.0

Aktuelle Sensordaten:
{$jsonData}

Antworte AUSSCHLIESSLICH mit einem JSON-Objekt in exakt diesem Format:
{
  \"water\": {
    \"activate\": boolean,
    \"duration_sec\": number,
    \"estimated_ml\": number,
    \"reason\": \"string\"
  },
  \"nutrient\": {
    \"activate\": boolean,
    \"duration_sec\": number,
    \"estimated_ml\": number,
    \"reason\": \"string\"
  },
  \"fan\": {
    \"should_be_on\": boolean,
    \"reason\": \"string\"
  },
  \"assessment\": {
    \"vpd\": \"string\",
    \"ec\": \"string\",
    \"health\": \"string (GUT/WARNUNG/KRITISCH)\",
    \"tips\": \"string\"
  },
  \"next_check_minutes\": number
}";
    }

    /**
     * Setzt den Tageszähler für Dünger zurück, wenn ein neuer Tag beginnt
     */
    private function resetDailyCounters(): void
    {
        $lastUpdate = IPS_GetVariable($this->GetIDForIdent('DailyNutrientML'))['VariableUpdated'];
        if ($lastUpdate > 0) {
            $lastDate = date('Y-m-d', $lastUpdate);
            $currentDate = date('Y-m-d');
            
            if ($lastDate !== $currentDate) {
                $this->SetValue('DailyNutrientML', 0.0);
                $this->SendDebug('ResetCounters', 'Tageszähler zurückgesetzt.', 0);
            }
        }
    }

    /**
     * Steuert eine Pumpe sicher (unterstützt sowohl Instanz-IDs als auch Variable-IDs)
     */
    private function setPumpLevel(int $pumpID, float $level, string $name = ''): bool
    {
        if ($pumpID === 0) {
            return false;
        }
        try {
            if (IPS_VariableExists($pumpID)) {
                RequestAction($pumpID, $level);
            } elseif (IPS_InstanceExists($pumpID)) {
                @HM_WriteValueFloat($pumpID, 'LEVEL', $level);
            } else {
                $this->SLogWarning("Ungültige Objekt-ID für Pumpe ($name): $pumpID");
                return false;
            }
            if ($name !== '') {
                $this->SLogInfo($name);
            }
            return true;
        } catch (Exception $e) {
            $this->SLogWarning("Pumpe konnte nicht gesteuert werden ($name): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validiert ob eine Pumpenoperation erlaubt ist (Licht, Dauer, Wartezeit, Tageslimit)
     */
    private function validatePumpOperation(string $type, int $durationSec, bool $isLightOn, float $estimatedML = 0.0): bool
    {
        $label = $type === 'water' ? 'Watering' : 'Nutrient';

        if (!$isLightOn) {
            $this->SendDebug($label, 'Licht ist aus, keine Aktion.', 0);
            return false;
        }

        $maxSecProp = $type === 'water' ? 'MaxWaterSec' : 'MaxNutrientSec';
        if ($durationSec > $this->ReadPropertyInteger($maxSecProp)) {
            $this->SendDebug($label, 'Angeforderte Dauer überschreitet Maximum.', 0);
            return false;
        }

        $waitProp = $type === 'water' ? 'MinWaterWaitMin' : 'MinNutrientWaitMin';
        $lastIdent = $type === 'water' ? 'LastWatering' : 'LastNutrient';
        if (time() - $this->GetValue($lastIdent) < $this->ReadPropertyInteger($waitProp) * 60) {
            $this->SendDebug($label, 'Mindestwartezeit nicht erreicht.', 0);
            return false;
        }

        // Zusätzliche Nutrient-Prüfungen
        if ($type === 'nutrient') {
            if ($this->ReadPropertyBoolean('FlushPhase')) {
                $this->SendDebug($label, 'FlushPhase aktiv, kein Dünger.', 0);
                return false;
            }
            $dailyNutrient = $this->GetValue('DailyNutrientML');
            $maxDaily = $this->ReadPropertyFloat('MaxNutrientDailyML');
            if (($dailyNutrient + $estimatedML) > $maxDaily) {
                $this->SendDebug($label, 'Tageslimit für Dünger erreicht.', 0);
                return false;
            }
        }

        return true;
    }

    /**
     * Liest einen Float-Wert sicher, mit Logging bei Fehler
     */
    private function safeGetFloat(int $variableID, string $context): ?float
    {
        try {
            return GetValueFloat($variableID);
        } catch (Exception $e) {
            $this->SLogWarning("Sensorwert konnte nicht gelesen werden ($context, ID $variableID): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Liest einen Boolean-Wert sicher, mit Logging bei Fehler
     */
    private function safeGetBool(int $variableID, string $context): ?bool
    {
        try {
            return GetValueBoolean($variableID);
        } catch (Exception $e) {
            $this->SLogWarning("Schaltstatus konnte nicht gelesen werden ($context, ID $variableID): " . $e->getMessage());
            return null;
        }
    }
}
