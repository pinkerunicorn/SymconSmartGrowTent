<?php

declare(strict_types=1);

/**
 * SmartGrowTent - IP-Symcon 9 Modul zur Automatisierung eines Cannabis Grow-Zelts.
 * 
 * @author Florian Graßinger
 * @url https://github.com/pinkerunicorn/
 */
class SmartGrowTent extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Registrierung der Properties
        $this->RegisterPropertyString('GeminiAPIKey', '');
        $this->RegisterPropertyString('GeminiModel', 'gemini-3.6-flash');
        
        $this->RegisterPropertyInteger('PumpWaterID', 0);
        $this->RegisterPropertyInteger('PumpNutrientID', 0);
        $this->RegisterPropertyInteger('PumpPHDownID', 0);
        
        $this->RegisterPropertyInteger('LightSwitchID', 0);
        $this->RegisterPropertyInteger('LightPowerID', 0);
        $this->RegisterPropertyInteger('FanSwitchID', 0);
        $this->RegisterPropertyInteger('FanPowerID', 0);
        
        $this->RegisterPropertyString('Plant1Name', 'Pflanze 1');
        $this->RegisterPropertyInteger('Plant1MoistureID', 0);
        $this->RegisterPropertyInteger('Plant1ECID', 0);
        
        $this->RegisterPropertyString('Plant2Name', 'Pflanze 2');
        $this->RegisterPropertyInteger('Plant2MoistureID', 0);
        $this->RegisterPropertyInteger('Plant2ECID', 0);
        
        $this->RegisterPropertyString('Plant3Name', 'Pflanze 3');
        $this->RegisterPropertyInteger('Plant3MoistureID', 0);
        $this->RegisterPropertyInteger('Plant3ECID', 0);
        
        $this->RegisterPropertyInteger('SphereTempID', 0);
        $this->RegisterPropertyInteger('SphereHumidityID', 0);
        $this->RegisterPropertyInteger('SphereLightID', 0);
        
        $this->RegisterPropertyInteger('LeakSensorID', 0);
        
        $this->RegisterPropertyInteger('MaxWaterSec', 1800);
        $this->RegisterPropertyInteger('MaxNutrientSec', 60);
        $this->RegisterPropertyFloat('MaxNutrientDailyML', 50.0);
        
        $this->RegisterPropertyInteger('MinWaterWaitMin', 60);
        $this->RegisterPropertyInteger('MinNutrientWaitMin', 30);
        
        $this->RegisterPropertyFloat('PumpWaterMLPerSec', 0.75);
        $this->RegisterPropertyFloat('PumpNutrientMLPerSec', 0.75);
        
        $this->RegisterPropertyString('GrowthPhase', 'vegetative');
        $this->RegisterPropertyInteger('WeekNumber', 1);
        $this->RegisterPropertyBoolean('FlushPhase', false);
        
        $this->RegisterPropertyInteger('AutomationInterval', 30);

        // Variablen-Profile erstellen (keine Legacy-Profile mit ~ verwenden!)
        if (!IPS_VariableProfileExists('SGT.VPD')) {
            IPS_CreateVariableProfile('SGT.VPD', 2); // Float
            IPS_SetVariableProfileDigits('SGT.VPD', 2);
            IPS_SetVariableProfileText('SGT.VPD', '', ' kPa');
            IPS_SetVariableProfileValues('SGT.VPD', 0, 3, 0.1);
        }

        if (!IPS_VariableProfileExists('SGT.Timestamp')) {
            IPS_CreateVariableProfile('SGT.Timestamp', 1); // Integer
            IPS_SetVariableProfileText('SGT.Timestamp', '', '');
        }

        if (!IPS_VariableProfileExists('SGT.Milliliter')) {
            IPS_CreateVariableProfile('SGT.Milliliter', 2); // Float
            IPS_SetVariableProfileDigits('SGT.Milliliter', 1);
            IPS_SetVariableProfileText('SGT.Milliliter', '', ' ml');
            IPS_SetVariableProfileValues('SGT.Milliliter', 0, 200, 1);
        }

        // Variablen Registrierung
        $this->RegisterVariableFloat('VPD', 'Aktueller VPD', 'SGT.VPD', 10);
        $this->RegisterVariableString('Health', 'Gesundheitsstatus', '', 20);
        $this->RegisterVariableFloat('DailyNutrientML', 'Tages-Dünger (ml)', 'SGT.Milliliter', 30);
        $this->RegisterVariableInteger('LastWatering', 'Letzte Bewässerung', 'SGT.Timestamp', 40);
        $this->RegisterVariableInteger('LastNutrient', 'Letzte Düngung', 'SGT.Timestamp', 50);
        $this->RegisterVariableString('LastGeminiResponse', 'Letzte KI-Antwort', '', 60);
        $this->RegisterVariableBoolean('SystemActive', 'System aktiv', '', 70);
        
        // RequestAction für SystemActive erlauben
        $this->EnableAction('SystemActive');

        // Timer registrieren
        $this->RegisterTimer('AutomationCycle', 0, 'SGT_RunAutomation($_IPS[\'TARGET\']);');
        $this->RegisterTimer('PumpWaterOff', 0, 'SGT_StopWaterPump($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Validierung der Konfiguration
        $apiKey = $this->ReadPropertyString('GeminiAPIKey');
        if (empty($apiKey)) {
            $this->SetStatus(200); // Kein API Key
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

        // Timer setzen
        $interval = $this->ReadPropertyInteger('AutomationInterval');
        if ($interval > 0 && $this->GetValue('SystemActive')) {
            $this->SetTimerInterval('AutomationCycle', $interval * 60 * 1000);
        } else {
            $this->SetTimerInterval('AutomationCycle', 0);
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'SystemActive':
                $this->SetValue($Ident, $Value);
                if ($Value) {
                    $interval = $this->ReadPropertyInteger('AutomationInterval');
                    $this->SetTimerInterval('AutomationCycle', $interval * 60 * 1000);
                    $this->LogMessage('System aktiviert.', KL_NOTIFY);
                } else {
                    $this->SetTimerInterval('AutomationCycle', 0);
                    $this->EmergencyStop();
                    $this->LogMessage('System deaktiviert. Notstopp ausgeführt.', KL_WARNING);
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

        // Leckageprüfung
        $leakSensorID = $this->ReadPropertyInteger('LeakSensorID');
        if ($leakSensorID !== 0 && @GetValueBoolean($leakSensorID)) {
            $this->EmergencyStop();
            $this->SetStatus(203); // Fehler: Leck erkannt
            $this->LogMessage('LECKAGE ERKANNT! Notstopp ausgeführt.', KL_ERROR);
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
            $this->EmergencyStop();
            $this->LogMessage("Zu viele Sensor-Anomalien ($anomalies). Notstopp ausgeführt.", KL_ERROR);
            return;
        }

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
            $this->LogMessage('Fehler bei der Kommunikation mit Gemini.', KL_ERROR);
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
            $maxWaterSec = $this->ReadPropertyInteger('MaxWaterSec');
            $minWaterWaitMin = $this->ReadPropertyInteger('MinWaterWaitMin');
            $lastWatering = $this->GetValue('LastWatering');
            
            if (!$isLightOn) {
                $this->SendDebug('Watering', 'Licht ist aus, keine Bewässerung.', 0);
            } elseif ($durationSec > $maxWaterSec) {
                $this->SendDebug('Watering', 'Angeforderte Dauer überschreitet Maximum.', 0);
            } elseif (time() - $lastWatering < $minWaterWaitMin * 60) {
                $this->SendDebug('Watering', 'Mindestwartezeit für Wasser nicht erreicht.', 0);
            } elseif ($durationSec > 0) {
                $pumpID = $this->ReadPropertyInteger('PumpWaterID');
                if ($pumpID !== 0) {
                    $this->LogMessage("Starte Bewässerung für $durationSec Sekunden. Grund: " . ($response['water']['reason'] ?? ''), KL_NOTIFY);
                    @HM_WriteValueFloat($pumpID, 'LEVEL', 1.0);
                    $this->SetTimerInterval('PumpWaterOff', $durationSec * 1000);
                    $this->SetValue('LastWatering', time());
                }
            }
        }

        // Dünger-Pumpe
        if (isset($response['nutrient']) && $response['nutrient']['activate'] === true) {
            $durationSec = (int)$response['nutrient']['duration_sec'];
            $maxNutrientSec = $this->ReadPropertyInteger('MaxNutrientSec');
            $minNutrientWaitMin = $this->ReadPropertyInteger('MinNutrientWaitMin');
            $lastNutrient = $this->GetValue('LastNutrient');
            $estimatedML = (float)($response['nutrient']['estimated_ml'] ?? 0);
            $dailyNutrient = $this->GetValue('DailyNutrientML');
            $maxDaily = $this->ReadPropertyFloat('MaxNutrientDailyML');
            
            if ($this->ReadPropertyBoolean('FlushPhase')) {
                $this->SendDebug('Nutrient', 'FlushPhase aktiv, kein Dünger.', 0);
            } elseif ($durationSec > $maxNutrientSec) {
                $this->SendDebug('Nutrient', 'Angeforderte Düngerdauer überschreitet Maximum.', 0);
            } elseif (time() - $lastNutrient < $minNutrientWaitMin * 60) {
                $this->SendDebug('Nutrient', 'Mindestwartezeit für Dünger nicht erreicht.', 0);
            } elseif (($dailyNutrient + $estimatedML) > $maxDaily) {
                $this->SendDebug('Nutrient', 'Tageslimit für Dünger erreicht.', 0);
            } elseif ($durationSec > 0) {
                $pumpID = $this->ReadPropertyInteger('PumpNutrientID');
                if ($pumpID !== 0) {
                    $this->LogMessage("Gebe Dünger für $durationSec Sekunden ($estimatedML ml). Grund: " . ($response['nutrient']['reason'] ?? ''), KL_NOTIFY);
                    @HM_WriteValueFloat($pumpID, 'LEVEL', 1.0);
                    IPS_Sleep($durationSec * 1000); // Sync Sleep für kurze Intervalle
                    @HM_WriteValueFloat($pumpID, 'LEVEL', 0.0);
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
                $isFanOn = @GetValueBoolean($fanSwitchID);
                if ($shouldBeOn !== $isFanOn) {
                    $this->LogMessage("Schalte Lüfter " . ($shouldBeOn ? "EIN" : "AUS") . ". Grund: " . ($response['fan']['reason'] ?? ''), KL_NOTIFY);
                    RequestAction($fanSwitchID, $shouldBeOn);
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
        if ($pumpID !== 0) {
            @HM_WriteValueFloat($pumpID, 'LEVEL', 0.0);
            $this->LogMessage('Bewässerung planmäßig gestoppt.', KL_NOTIFY);
        }
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

        $waterPump = $this->ReadPropertyInteger('PumpWaterID');
        if ($waterPump !== 0) {
            @HM_WriteValueFloat($waterPump, 'LEVEL', 0.0);
        }
        $nutrientPump = $this->ReadPropertyInteger('PumpNutrientID');
        if ($nutrientPump !== 0) {
            @HM_WriteValueFloat($nutrientPump, 'LEVEL', 0.0);
        }
        $phDownPump = $this->ReadPropertyInteger('PumpPHDownID');
        if ($phDownPump !== 0) {
            @HM_WriteValueFloat($phDownPump, 'LEVEL', 0.0);
        }

        $this->LogMessage('NOTSTOPP ALLER PUMPEN DURCHGEFÜHRT!', KL_ERROR);
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

        @HM_WriteValueFloat($pumpID, 'LEVEL', 1.0);
        IPS_Sleep($seconds * 1000);
        @HM_WriteValueFloat($pumpID, 'LEVEL', 0.0);

        return "Pumpe $type für $seconds Sekunden aktiviert. Bitte messen Sie das Volumen.";
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
        $apiKey = $this->ReadPropertyString('GeminiAPIKey');
        $model = $this->ReadPropertyString('GeminiModel');
        
        if (empty($apiKey)) {
            $this->SendDebug('Gemini API', 'Kein API Key konfiguriert', 0);
            return null;
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        
        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json'
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->SendDebug('Gemini API Error', 'HTTP ' . $httpCode . ' Response: ' . $result, 0);
            return null;
        }

        $data = json_decode($result, true);
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $jsonText = $data['candidates'][0]['content']['parts'][0]['text'];
            return json_decode($jsonText, true);
        }

        return null;
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
                    'moisture' => $moistID !== 0 ? @GetValueFloat($moistID) : null,
                    'ec' => $ecID !== 0 ? @GetValueFloat($ecID) : null
                ];
            }
        }

        // Sphere Sensoren
        $tempID = $this->ReadPropertyInteger('SphereTempID');
        if ($tempID !== 0) $data['sphere']['temp'] = @GetValueFloat($tempID);
        
        $humID = $this->ReadPropertyInteger('SphereHumidityID');
        if ($humID !== 0) $data['sphere']['humidity'] = @GetValueFloat($humID);
        
        $lightID = $this->ReadPropertyInteger('SphereLightID');
        if ($lightID !== 0) $data['sphere']['light_ppfd'] = @GetValueFloat($lightID);

        // Equipment
        $lightSwitchID = $this->ReadPropertyInteger('LightSwitchID');
        if ($lightSwitchID !== 0) $data['equipment']['light_switch'] = @GetValueBoolean($lightSwitchID);
        
        $lightPowerID = $this->ReadPropertyInteger('LightPowerID');
        if ($lightPowerID !== 0) $data['equipment']['light_power_w'] = @GetValueFloat($lightPowerID);
        
        $fanSwitchID = $this->ReadPropertyInteger('FanSwitchID');
        if ($fanSwitchID !== 0) $data['equipment']['fan_switch'] = @GetValueBoolean($fanSwitchID);
        
        $fanPowerID = $this->ReadPropertyInteger('FanPowerID');
        if ($fanPowerID !== 0) $data['equipment']['fan_power_w'] = @GetValueFloat($fanPowerID);

        // Historische Daten
        $data['tracking'] = [
            'last_watering_unix' => $this->GetValue('LastWatering'),
            'last_nutrient_unix' => $this->GetValue('LastNutrient'),
            'daily_nutrient_ml' => $this->GetValue('DailyNutrientML')
        ];

        return $data;
    }

    /**
     * Baut den Prompt für Gemini
     */
    private function buildGeminiPrompt(array $sensorData): string
    {
        $phase = $this->ReadPropertyString('GrowthPhase');
        $week = $this->ReadPropertyInteger('WeekNumber');
        $flush = $this->ReadPropertyBoolean('FlushPhase') ? 'JA' : 'NEIN';
        
        $waterPumpML = $this->ReadPropertyFloat('PumpWaterMLPerSec');
        $nutrientPumpML = $this->ReadPropertyFloat('PumpNutrientMLPerSec');
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
}
