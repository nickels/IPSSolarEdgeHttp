<?

class SolarEdgeHTTPAPI extends IPSModule {
    /**
     * @var string
     */
    private $baseUrl;

    /**
     * SolarEdgeHTTPAPI constructor.
     * @param $InstanceID
     */
    public function __construct($InstanceID) {
        parent::__construct($InstanceID);

        $this->baseUrl = 'https://monitoringapi.solaredge.com/';
    }

    /**
     * @return bool|void
     */
    public function Create() {
        parent::Create();

        $this->RegisterPropertyString('apiKey', '');

        $this->setVariableProfile("SOLAREDGE.kiloWattPeak", 2, "Electricity", "", " kWp", 0, 10, 0.1, 1);
        $this->setVariableProfile("SOLAREDGE.Watt", 1, "Electricity", "", " W");
        $this->setVariableProfile("SOLAREDGE.kiloWattHour", 2, "Electricity", ""," kWh", 0, null, 0.1, 2);
        $this->setVariableProfile("SOLAREDGE.megaWattHour", 2, "Electricity", "", " MWh", 0, null, 0.1, 2);

        $this->RegisterVariableInteger('accountId', 'Account ID', null, 1);
        $this->RegisterVariableInteger('siteID', 'Site ID', null, 2);
        $this->RegisterVariableFloat('peakPower', 'Peak Power', "SOLAREDGE.kiloWattPeak", 3);

        $this->RegisterTimer("UpdateCurrentPower", 5*60*1000, 'SOLAREDGE_updateCurrentPower($_IPS[\'TARGET\']);');
    }

    /**
     * @return bool|void
     */
    public function ApplyChanges() {
        parent::ApplyChanges();

        if(is_null($this->ReadPropertyString('apiKey'))){
            return;
        }

        $siteDetails = $this->getSiteDetails($this->ReadPropertyString('apiKey'));
        SetValue($this->GetIDForIdent('siteID'), $siteDetails['siteID']);
        SetValue($this->GetIDForIdent('accountId'), $siteDetails['accountId']);
        SetValue($this->GetIDForIdent('peakPower'), $siteDetails['peakPower']);

        $archive = IPS_GetInstanceIDByName("Archive", 0 );

        AC_SetLoggingStatus($archive, $this->RegisterVariableInteger('currentPower', 'Current Power', "SOLAREDGE.Watt", 4), true);
        AC_SetLoggingStatus($archive, $this->RegisterVariableFloat('lastDayData', 'Today', "SOLAREDGE.kiloWattHour", 5), true);
        AC_SetLoggingStatus($archive, $this->RegisterVariableFloat('lastMonthData', 'This Month', "SOLAREDGE.kiloWattHour", 6), true);
        AC_SetLoggingStatus($archive, $this->RegisterVariableFloat('lastYearData', 'This Year', "SOLAREDGE.megaWattHour", 7), true);

        $this->RegisterVariableFloat('lifeTimeData', 'All Time', "SOLAREDGE.megaWattHour", 8);
        $this->RegisterVariableString('lastUpdateTime', 'Last Updated', null, 9);

    }

    /**
     * @param string $apiKey
     * @return mixed
     */
    public function getSiteDetails($apiKey) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, "{$this->baseUrl}sites/list?api_key={$apiKey}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $result = json_decode(curl_exec($ch), true);

        if (curl_errno($ch)) {
            curl_error($ch);
        }
        curl_close($ch);

        $details['siteID'] = $result["sites"]["site"][0]["id"];
        $details['accountId'] = $result["sites"]["site"][0]["accountId"];
        $details['peakPower'] = $result["sites"]["site"][0]["peakPower"];

        return $details;
    }

    private function setVariableProfile($name, $profileType, $icon, $prefix = "", $suffix = "", $minValue = null, $maxValue = null, $stepSize = 1, $digits = 0)
    {
        if(!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $profileType);
        }

        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileValues($name, $minValue, $maxValue, $stepSize);
        IPS_SetVariableProfileDigits($name, $digits);
    }

    /**
     * @return
     */
    public function updateCurrentPower() {
        if(is_null($this->ReadPropertyString('apiKey'))){
            return;
        }

        if($this->isNightTime() && $this->isNotSameDayAsLast()){
            return;
        }

        $ch = curl_init();

        $siteId = GetValue($this->GetIDForIdent('siteID'));
        $apiKey = $this->ReadPropertyString('apiKey');
        curl_setopt($ch, CURLOPT_URL, "{$this->baseUrl}site/{$siteId}/overview?systemUnits=Metrics&api_key={$apiKey}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $result = json_decode(curl_exec($ch), true);

        if (curl_errno($ch)) {
            curl_error($ch);
        }
        curl_close($ch);

        if($this->isDayTime()) {
            SetValue($this->GetIDForIdent('currentPower'), $result['overview']['currentPower']['power']);
        }

        if(GetValue($this->GetIDForIdent('lastDayData')) !== ($result['overview']['lastDayData']['energy'] / 1000)) {
            SetValue($this->GetIDForIdent('lastDayData'), $result['overview']['lastDayData']['energy'] / 1000);
        }

        if(GetValue($this->GetIDForIdent('lastMonthData')) !== ($result['overview']['lastMonthData']['energy'] / 1000)) {
            SetValue($this->GetIDForIdent('lastMonthData'), $result['overview']['lastMonthData']['energy'] / 1000);
        }

        if(GetValue($this->GetIDForIdent('lastYearData')) !== ($result['overview']['lastYearData']['energy'] / 1000000)) {
            SetValue($this->GetIDForIdent('lastYearData'), $result['overview']['lastYearData']['energy'] / 1000000);
        }

        if(GetValue($this->GetIDForIdent('lifeTimeData')) !== ($result['overview']['lifeTimeData']['energy'] / 1000000)) {
            SetValue($this->GetIDForIdent('lifeTimeData'), $result['overview']['lifeTimeData']['energy'] / 1000000);
        }

        if(GetValue($this->GetIDForIdent('lastUpdateTime')) !== $result['overview']['lastUpdateTime']) {
            SetValue($this->GetIDForIdent('lastUpdateTime'), $result['overview']['lastUpdateTime']);
        }
    }

    private function isNightTime()
    {
        $LocationID = IPS_GetInstanceListByModuleID("{45E97A63-F870-408A-B259-2933F7EABF74}")[0];
        return !GetValue(IPS_GetObjectIDByIdent("IsDay", $LocationID));
    }

    private function isDayTime(){
        return ! $this->isNightTime();
    }

    private function isNotSameDayAsLast()
    {
        return ( date_parse(GetValue($this->GetIDForIdent('lastUpdateTime')))['day'] !== (int) (new DateTime('now'))->format('d'));
    }
}
?>