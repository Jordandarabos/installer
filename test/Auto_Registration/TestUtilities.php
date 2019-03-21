<?php

class TestUtilities {
        
    public $baseURL = null;
    public $apiKey = null;
    public $developerKey = null;
    public $apiToken = null;
    
    public $responses = null;
      

function __construct($url, $devKey, $token = null) {
    $this->baseURL = $url;
    $this->developerKey = $devKey;
    
    if (isset($token)) $this->apiToken = $token;
    
    //echo "const token=$this->apiToken\n";
    
    $this->responses = array();
}

function set_API_Key($key) {
    $this->apiKey = $key;
}

function setHeaders($devKeyOverride = null) {

    $headers = array(
        'Content-Type: application/json' );
    
    $keyName = "";
    if (isset($this->apiKey) && strlen($this->apiKey) > 0) {
        $keyName = "apikey";
    } else if (isset($this->developerKey) && strlen($this->developerKey) > 0) {
        $keyName = "developerkey";
    }
    
    if (isset($devKeyOverride) && strlen($devKeyOverride) == 0) {
        
        // skip the header
        
    } else if (isset($devKeyOverride) && strlen($devKeyOverride) > 0) {
        
        //echo "key override\n";
        array_push($headers, $keyName.': '.$devKeyOverride);
    }
    
    //echo "get token=$this->apiToken\n"
    //echo "get devkey=$this->apiToken\n";
    //echo "get override=$this->apiToken\n";
    
    if (!isset($devKeyOverride) && isset($this->developerKey) && isset($this->apiToken)) {
        
        //echo "key and token\n";
        array_push($headers, $keyName.': '.$this->developerKey, 'token: '.$this->apiToken);
    }
    
    if (!isset($devKeyOverride) && isset($this->developerKey) && !isset($this->apiToken)) {
        
        //echo "key only\n";
        array_push($headers, $keyName.': '.$this->developerKey);
    }
    
    echo "headers:".print_r($headers,true);
    
    return $headers;
}

function get_api($uri, $queryString, $description, $devKeyOverride = null) {
    echo "API $description ...\n";
    echo "    GET $this->baseURL$uri$queryString\n";
    
    $ch = curl_init($this->baseURL.$uri.$queryString);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
    $headers = $this->setHeaders($devKeyOverride);
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "    [$httpcode]: $result\n\n";
    
    if (strpos($result,"reason") > 1) {
        
        $item = json_decode($result, true);
        
        if (is_array($item)) {
            
            $new_array = array(" ".$httpcode." ".$item['reason']=>'true');
            $this->responses = array_merge($this->responses, $new_array);
        } else {
            
            $new_array = array(" ".$httpcode." ".$result=>'true');
            $this->responses = array_merge($this->responses, $new_array);
        }
    }
    
    return array("status" => $httpcode, "message" => $result);
    
}

function post_api($uri, $data, $description, $devKeyOverride = null) {
    
    echo "API $description ...\n";
    echo "    POST $this->baseURL$uri Body:".print_r($data,true)."\n";
     
    $ch = curl_init($this->baseURL.$uri);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $headers = $this->setHeaders($devKeyOverride);
    
    if (isset($data) && count($data) > 0) {
        
        //echo "add data\n";
        
        $data_string = json_encode($data);
        array_push($headers, 'Content-Length: ' . strlen($data_string));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "    [$httpcode]: $result\n\n";  
    
    if (strpos($result,"reason") > 1) {    
            
        $item = json_decode($result, true);
        
        if (is_array($item)) {
            
            $new_array = array(" ".$httpcode." ".$item['reason']=>'true');  
            $this->responses = array_merge($this->responses, $new_array);
        } else {
            
            $new_array = array(" ".$httpcode." ".$result=>'true');
            $this->responses = array_merge($this->responses, $new_array);
         }
    }
    
    return array("status" => $httpcode, "message" => $result);
}

function delete_api($uri, $data, $description, $devKeyOverride = null) {
    
    echo "API $description ...\n";
    echo "    DELETE $this->baseURL$uri Body:".print_r($data,true)."\n";
    
    $ch = curl_init($this->baseURL.$uri);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $headers = array(
        'Content-Type: application/json' );
    
    if (isset($devKeyOverride) && strlen($devKeyOverride) == 0) {
        
        // skip the header
        //echo "skip header\n";
        
    } else if (isset($devKeyOverride) && strlen($devKeyOverride) > 0) {
        
        //echo "key override\n";
        array_push($headers, 'developerkey: '.$devKeyOverride);
    }
    
    //echo "post token=$this->apiToken\n";
    
    if (!isset($devKeyOverride) && isset($this->developerKey) && isset($this->apiToken)) {
        
        //echo "key and token\n";
        array_push($headers, 'developerkey: '.$this->developerKey, 'token: '.$this->apiToken);
    }
    
    if (!isset($devKeyOverride) && isset($this->developerKey) && !isset($this->apiToken)) {
        
        //echo "key only\n";
        array_push($headers, 'developerkey: '.$this->developerKey);
    }
    
    if (isset($data) && count($data) > 0) {
        
        //echo "add data\n";
        
        $data_string = json_encode($data);
        array_push($headers, 'Content-Length: ' . strlen($data_string));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "    [$httpcode]: $result\n\n";
    
    if (strpos($result,"reason") > 1) {
        
        $item = json_decode($result, true);
        
        if (is_array($item)) {
            
            $new_array = array(" ".$httpcode." ".$item['reason']=>'true');
            $this->responses = array_merge($this->responses, $new_array);
        } else {
            
            $new_array = array(" ".$httpcode." ".$result=>'true');
            $this->responses = array_merge($this->responses, $new_array);
        }
    }
    
    return array("status" => $httpcode, "message" => $result);
}

function test_post_basics($uri) {
    
    $this->post_api($uri, null, "Missing POST Body");    
    $this->post_api($uri, null, "Missing developerkey", "");
    $this->post_api($uri, null, "Bad developerkey", "junk");
}

function test_get_basics($uri) {
    
    $this->get_api($uri, "", "Missing POST Body");    
    $this->get_api($uri, "", "Missing developerkey", "");
    $this->get_api($uri, "", "Bad developerkey", "junk");
}

function test_delete_basics($uri) {
    
    $this->delete_api($uri, null, "Missing POST Body");
    $this->delete_api($uri, null, "Missing developerkey", "");
    $this->delete_api($uri, null, "Bad developerkey", "junk");
}

}

?>
