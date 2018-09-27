<?php

require_once('Route.class.php');

class CorporationInformation extends Route {
    
    public function __construct($esi) {
        parent::__construct($esi);
        $this->setRoute('/v4/corporations/');
        $this->setCacheInterval(3600);
    }
    
    public function getCorporation($corporationID = null) {
        //secondly, get the corporation information
        if (is_null($corporationID)) $corporationID = $this->ESI->getCorporationID();
        $this->setRoute('/v4/corporations/');
        $this->setCacheInterval(3600);
        $corp = $this->get( $corporationID . '/');
        if ($this->ESI->getDEBUG()) var_dump($corp);
        if (is_object($corp)) return $corp;
        return FALSE;
    }
    
    public function getDivisions($corporationID = null) {
        if (is_null($corporationID)) $corporationID = $this->ESI->getCorporationID();
        $this->setRoute('/v1/corporations/');
        $this->setCacheInterval(3600);
        $divisions = $this->get(  $corporationID . '/divisions/');
        return $divisions;
    }
    
    public function updateDivisions() {
        inform(get_class(), 'Updating Corporation Divisions...');
        $divisions = $this->getDivisions();
        
        if ($this->getStatus()=='fresh') {
            // apidivisions
            // corporationID 	accountKey 	description
            if (property_exists($divisions, 'hangar') && count($divisions->hangar) > 0){
                db_uquery("DELETE FROM `apidivisions` WHERE `corporationID` = " . $this->ESI->getCorporationID());
                $i = 0;
                foreach ($divisions->hangar as $h) {
                    $sql = "INSERT INTO `apidivisions` VALUES(" . $this->ESI->getCorporationID() . "," . $this->v($h, 'division',$i++) . "," . $this->s($this->v($h, 'name', '')) . ")";
                    db_uquery($sql);
                }
            } else {
                warning(get_class(), 'Updating Corporation Hangar Divisions Failed: no data returned by ESI.');
            }
            // apiwalletdivisions
            // corporationID 	accountKey 	description
            if (property_exists($divisions, 'wallet') && count($divisions->wallet) > 0){
                db_uquery("DELETE FROM `apiwalletdivisions` WHERE `corporationID` = " . $this->ESI->getCorporationID());
                $i = 0;
                foreach ($divisions->wallet as $h) {
                    $sql = "INSERT INTO `apiwalletdivisions` VALUES(" . $this->ESI->getCorporationID() . "," . $this->v($h, 'division',$i++) . "," . $this->s($this->v($h, 'name', '')) . ")";
                    db_uquery($sql);
                }
            } else {
                warning(get_class(), 'Updating Corporation Wallet Divisions Failed: no data returned by ESI.');
            }
        } else {
            inform(get_class(), 'Route ' . $this->getRoute() . $this->getParams() . ' is still cached, skipping...');
            return TRUE;
        }
        return TRUE;
    }
    
    public function updateCorporationInformation() {
        inform(get_class(), 'Updating CorporationInformation...');
    //first, get corporation_id based on character_id who owns the ESI Token
        try {
            $data = $this->ESI->Characters->getCharacter($this->ESI->getCharacterID());
            $this->ESI->setCorporationID($data->corporation_id);
        } catch (Exception $e) {
            $msg = 'Cannot get corporation_id from ESI Token owner: '. $e->getMessage();
            warning(get_class(), $msg);
            throw new Exception($msg);
        }
    //secondly, get the corporation information
        $corp = $this->get( $this->ESI->getCorporationID() . '/');
        if ($this->ESI->getDEBUG()) var_dump($corp);
    //then, get the CEO name
        $ceo = $this->ESI->Characters->getCharacter($corp->ceo_id);
    //next, get the member limit - note we need /v1/ route for that
        $this->setRoute('/v1/corporations/');
        $member_limit = $this->get( $this->ESI->getCorporationID() . '/members/limit/');
        if (!is_numeric($member_limit)) $member_limit = 0;
    //next, get the Home Station name
        $station = $this->ESI->Stations->getStation($corp->home_station_id);
    //next INSERT into "short corp table" `apicorps`
        $sql="INSERT INTO apicorps VALUES (". $this->ESI->getCorporationID() . "," . 
                $this->s($corp->name) . "," .
                $corp->ceo_id."," .
                $this->s($ceo->name) . ",".
                "NULL," .
                $this->ESI->getTokenID() .    
                ")" .
            "ON DUPLICATE KEY UPDATE " .
                "`corporationName` = " . $this->s($corp->name) . "," .
                "`characterID` = " . $corp->ceo_id . "," .
                "`characterName` = " . $this->s($ceo->name) . "," .
                "`tokenID` = " . $this->ESI->getTokenID() . 
            ";";
	db_uquery($sql);
    //finally, INSERT INTO "long corp table" `apicorpsheet`
        if (!isset($corp->alliance_id)) $corp->alliance_id = 0;
        $sql="INSERT INTO apicorpsheet VALUES (".
                $this->ESI->getCorporationID() . ",".
                $this->s($corp->name) . "," .
                $this->s($corp->ticker) . "," .
                $corp->ceo_id.",".
                $this->s($ceo->name) . "," .
                $corp->home_station_id.",".
                $this->s($station->name) . "," .
                $this->s($corp->description) . "," .
                $this->s($corp->url) . "," .
                $corp->alliance_id . ",".
                floor($corp->tax_rate * 100) . ",".
                $corp->member_count . ",".
                $member_limit . ",".
                $corp->shares . ",".
                "0,".
                "0,".
                "0,".
                "0,".
                "0,".
                "0,".
                "0) ".
            "ON DUPLICATE KEY UPDATE " .
                "`corporationName` = " . $this->s($corp->name) . "," .
                "`ticker` = " . $this->s($corp->ticker) . "," .
                "`ceoID` = " . $corp->ceo_id . "," .
                "`ceoName` = " . $this->s($ceo->name) . "," .
                "`stationID` = " . $corp->home_station_id . ',' .
                "`stationName` = " . $this->s($station->name) . "," .
                "`description` = " . $this->s($corp->description) . "," .
                "`url` = " . $this->s($corp->url) . "," .
                "`allianceID` = " . $corp->alliance_id . ',' .
                "`taxRate` = " . floor($corp->tax_rate * 100) . ',' .
                "`memberCount` = " . $corp->member_count . ',' .
                "`memberLimit` = " . $member_limit . ',' .
                "`shares` = " . $corp->shares . ',' .
                "`graphicId` = 0," .
                "`shape1` = 0," .
                "`shape2` = 0," .
                "`shape3` = 0," .
                "`color1` = 0," .
                "`color2` = 0," .
                "`color3` = 0" .
            ";";
	db_uquery($sql);
    }
    
    public function update() {
        $this->updateCorporationInformation();
        $this->updateDivisions();
    }
    
}
