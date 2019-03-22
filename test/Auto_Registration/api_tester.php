<?php
// usage: api_tester.php <config_file> <test_csv_file> <scope> <endpoint>
// example php ../api_tester.php config.ini test.csv [detail | summary] https://api01.remot3.it/apv/latest

include_once "TestUtilities.php";
$GLOBALS['responses'] = array();
$GLOBALS['failedcalls'] = array();

$api_count = 0;
$success_count = 0;
$failure_count = 0;
$success_goal = 0;
$failure_goal = 0;

// $SCOPE_INDEX = 0;
$METHOD_INDEX = 0;
$ASSERTION_INDEX = 1;
$PATH_INDEX = 2;
$HEADERS_INDEX = 3;
$BODY_INDEX = 4;
$ACTION_INDEX = 5;
$UPLOAD_INDEX = 6;

// parse the CLI
/*
 * -cf config
 * -tf test file
 * -m detail or summary mode
 * -url root URL
 * -u account
 * -p password
 * -start label
 * -context file
 */

$shortopts = "";
$shortopts .= "l:"; // Required value
$shortopts .= "c:"; // Required value
$shortopts .= "t:"; // Required value
$shortopts .= "m:"; // Required value
$shortopts .= "a:"; // Required value
$shortopts .= "u:"; // Required value
$shortopts .= "p:"; // Required value
$shortopts .= "x:"; // Required value
$shortopts .= "i:"; // Required value
$shortopts .= "q:"; // Required value

$options = getopt("l:c:t:m:a:u:p:x:i:q:");

$ok = false;
if (isset($options["c"]) && isset($options["t"]) && isset($options["m"]) && isset($options["a"]) && isset($options["p"]) && isset($options["l"])) {
    $ok = true;
} else {
    echo "Usage problem:\n";
}

if (! isset($options["l"]))
    echo "missing -l <test label>\n";
if (! isset($options["c"]))
    echo "missing -c <config file>\n";
if (! isset($options["t"]))
    echo "missing -t <tests file>\n";
if (! isset($options["m"]))
    echo "missing -m <mode detail or summary or results>\n";
if (! isset($options["a"]))
    echo "missing -a <api root url>\n";
if (! isset($options["p"]))
    echo "missing -p <password>\n";

if (isset($options["i"]) && file_exists("saved.ini")) {
    $use_saved_context = true;
    $ini_array = parse_ini_file("saved.ini");
} else {
    
    if (file_exists($options["c"])) {
        $ini_array = parse_ini_file($options["c"]);
    } else {
        echo "Problem opening config file " . $options["c"] . "\n";
        $ok = false;
    }
    
    // use the specified account if not entered
    if ((! isset($options["u"])) && (! isset($ini_array['<username>']))) {
        echo "missing -u <account>\n";
        $ok = false;
    } else if (isset($options["u"]) && (! isset($ini_array['<username>']))) {
        $ini_array['<username>'] = $options["u"];
    } else if (isset($options["u"]) && (isset($ini_array['<username>']))) {
        echo "ignoring -u parameter based on config file\n";
    }
    
    $detail = $options["m"];
    $GLOBALS['detail'] = $detail;
    
    $ini_array['<endpoint>'] = $options["a"];
    $ini_array['<password>'] = $options["p"];
    
    if (! isset($ini_array['<scope>'])) {
        echo "you need to set the scope in the config file\n";
    }
}

if (isset($options["x"])) {
    $start_label = $options["x"];
}

if (isset($options["q"])) {
    $GLOBALS['quiet'] = true;
}

$GLOBALS['label'] = $options["l"];
if (file_exists($GLOBALS['label'] . ".out"))
    unlink($GLOBALS['label'] . ".out");

if (! $ok)
    exit();

if (! isset($GLOBALS['quiet']))
    echo "\nAutomated API test for " . $options["l"] . "\n";

$csv = array();
if (($handle = fopen($options["t"], "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
        $num = count($data);
        $csv[] = $data;
    }
    fclose($handle);
}

// process each test
$apiCount = 0;
$skipping = false;
$skipTo = "";

$fileContent = array();
$fileRecords = 0;
$fileCurrentRow = 0;

$rows = count($csv);
$currentRow = - 1;

// report_detail(">>>> " . print_r($csv,true));
function findLabel($label, $rows)
{
    for ($i = 0; $i < count($rows); $i ++) {
        
        $row = $rows[$i];
        if (strpos($row[0], $label) === 0) {
            return $i;
        }
    }
    return false;
}

// foreach ($csv as $row) {
while (true) {
    
    // report_detail(">>>> row ".$currentRow. " of " . $rows. " is " . print_r($csv[$currentRow],true));
    
    $currentRow = $currentRow + 1;
    
    if ($currentRow > $rows) {
        report_detail("=========================== no more records exiting ===========================");
        break;
    }
    
    $row = $csv[$currentRow];
    
    $method = $row[$METHOD_INDEX];
    
    $assertion = "200";
    if (isset($row[$ASSERTION_INDEX])) $assertion = $row[$ASSERTION_INDEX];
    
    // skip comments
    if (substr($method, 0, 1) === "#")
        continue;
    
    // perform wait then skip
    if ($method == "EXIT") {
        report_detail("=========================== forced exit ===========================");
        break;
    }
    
    if ($method == "SKIP" || isset($start_label)) {
        
        if (isset($start_label)) {
            $foundRow = findLabel($start_label, $csv);
            unset($start_label);
        } else {
            $foundRow = findLabel($row[$PATH_INDEX], $csv);
        }
        
        if ($foundRow === FALSE) {} else {
            $currentRow = $foundRow;
            $skipLabel = $row[$PATH_INDEX];
            report_detail("=========================== " . $method . " to " . $skipLabel . " ===========================");
            
            continue;
        }
    }
    
    // perform wait then skip
    if ($method == "FILE") {
        
        if ($assertion == "OPEN") {
            
            $filepath = $row[$PATH_INDEX];
            if (! file_exists($filepath)) {
                report_detail("=========================== " . $filepath . " failed " . " ===========================");
            } else {
                $fileContent = file($filepath);
                $fileRecords = count($fileContent);
                $fileCurrentRow = 0;
                
                report_detail("=========================== " . $filepath . " available for READ " . count($fileContent) . " lines ===========================");
            }
        } else if ($assertion == "READ") {
            
            // check eof
            if ($fileCurrentRow >= $fileRecords) {
                report_detail("=========================== EOF forced exit ===========================");
                break;
            }
            
            $variable = $row[$HEADERS_INDEX];
            $error_location = $row[$BODY_INDEX];
            
            report_detail("=========================== " . $method . " " . $variable . " " . trim($fileContent[$fileCurrentRow]) . " ===========================");
            
            $ini_array[$variable] = trim($fileContent[$fileCurrentRow]);
            $fileCurrentRow = $fileCurrentRow + 1;
        }
        continue;
    }
    
    // perform wait then skip
    if ($method == "WAIT") {
        $wait = $row[$PATH_INDEX];
        $reason = $row[$HEADERS_INDEX];
        report_detail("=========================== " . $method . " " . $wait . " " . $reason . " ===========================");
        sleep($wait);
        continue;
    }
    
    if ($method == "ECHO") {
        $t = $row[$PATH_INDEX];
        $v = $row[$HEADERS_INDEX];
        echo $t . $ini_array[$v] . "\n";
        continue;
    }
    
    if ($method == "SAVE_CONTEXT") {
        if (file_exists("saved.ini"))
            unlink("saved.ini");
        write_php_ini($ini_array, "saved.ini");
        continue;
    }
    
    if ($method == "READ_CONTEXT" && file_exists("saved.ini")) {
        $tmp_array = parse_ini_file("saved.ini");
        continue;
    }
    
    if ($method == "RANDOM_STRING") {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $string = '';
        $max = strlen($characters) - 1;
        for ($i = 0; $i < 8; $i ++) {
            $string .= $characters[mt_rand(0, $max)];
        }
        
        $prepend = $row[$PATH_INDEX];
        $variable = $row[$HEADERS_INDEX];
        $append = $row[$BODY_INDEX];
        
        report_detail("=========================== " . $method . " " . $variable . " " . $prepend . $string . $append . " ===========================");
        
        $ini_array[$variable] = $prepend . $string . $append;
        
        continue;
    }
    
    $apiCount = $apiCount + 1;
    
    $path = $row[$PATH_INDEX];
    report_detail("=========================== " . $method . " " . $path . " ===========================");
    
    // look for variables in the path
    $path_variables = array();
    $path_parts = explode("/", $path);
    foreach ($path_parts as $item) {
        
        if (startsWith($item, ":")) {
            $path_variables[] = $item;
        }
    }
    // echo "path arguments: " . print_r($path_variables, true) . "\n";
    
    // process headers
    $header = $row[$HEADERS_INDEX];
    $headers = explode(",", $header);
    // echo "headers: ".print_r($headers,true)."\n";
    
    // echo print_r($ini_array,true);
    
    // process the body
    $body = $row[$BODY_INDEX];
    $body_args = json_decode($body);
    // echo "body: " . print_r($body_args, true) . "\n";
    
    // process actions
    $action = $row[$ACTION_INDEX];
    $actions = explode(",", $action);
    // echo "actions: " . print_r($actions, true) . "\n";
    
    // key tests
    if ($method != "UPLOAD") {
        $api_headers = array(
            'Content-Type: application/json'
        );
    } else {
        $api_headers = array();
    }
    
    $apikey = false;
    $developerkey = false;
    $token = false;
    $nextgen = false;
    foreach ($headers as $item) {
        $nvp = explode("=", $item);
        // echo "nvp:".print_r($nvp,true);
        
        if (startsWith($nvp[1], "<") && endsWith($nvp[1], ">") && isset($ini_array[$nvp[1]])) {
            // echo "add:" . $nvp[0] . " as " . $ini_array[$nvp[1]];
            $api_headers[] = $nvp[0] . ": " . $ini_array[$nvp[1]];
        }
        
        if ($nvp[0] == "apikey")
            $apikey = true;
        if ($nvp["0"] == "developerkey")
            $developerkey = true;
        if ($nvp["0"] == "token")
            $token = true;
        if ($nvp["0"] == "nextgen")
            $nextgen = ($nvp[1] == 'true') ? true : false;
    }
    
    // echo "body args:" . print_r($body_args, true) . "\n";
    // echo "body :" . $body . "\n";
    
    $api_data = array();
    if (isset($body_args) && count($body_args) > 0) {
        foreach ($body_args as $key => $value) {
            // echo "check:" . $key . " is_object:" . is_object($value) . "\n";
            if (is_object($value)) {
                
                foreach ($value as $key2 => $value2) {
                    // echo "check:" . $key2 . "\n";
                    if (startsWith($value2, "<") && endsWith($value2, ">") && isset($ini_array[$value2])) {
                        // echo "add:" . $key2 . " as " . $ini_array[$value2] . "\n";
                        $api_data[$key][$key2] = $ini_array[$value2];
                    } else {
                        $api_data[$key][$key2] = $value2;
                    }
                }
            } else {
                if (startsWith($value, "<") && endsWith($value, ">") && isset($ini_array[$value])) {
                    // echo "add:" . $key . " as " . $ini_array[$value] . "\n";
                    $api_data[$key] = $ini_array[$value];
                } else {
                    $api_data[$key] = $value;
                }
            }
        }
    }
    // echo "api data:" . print_r($api_data, true) . "\n";
    
    $api_path = $path;
    if (isset($path_variables) && count($path_variables) > 0) {
        foreach ($path_variables as $key => $value) {
            
            if (isset($ini_array[$value])) {
                $api_path = str_replace($value, $ini_array[$value], $api_path);
            } else {
                $newValue = "<" . str_replace(":", "", $value) . ">";
                // echo "new value:" . $newValue . "\n";
                $api_path = str_replace($value, $ini_array[$newValue], $api_path);
            }
        }
    }
    // echo "api path:" . $api_path . "\n";
    
    // echo print_r($api_data,true)."\n";
    $data = json_encode($api_data, true);
    // echo "data:" . $data . "\n";
    
    // all but expected method (GET, POST, DELETE)
    $methods = array(
        "GET",
        "POST",
        "DELETE"
    );
    
    // GET processing
    if ($method == "GET") {
        
        if ($ini_array['<scope>'] == "all" || $ini_array['<scope>'] == "headers") {
            
            if ($apikey == true || $developerkey == true) {
                // test bad keys
                $bad_headers = array(
                    'Content-Type: application/json'
                );
                get_api($ini_array['<endpoint>'], $api_path, 'No keys', $bad_headers, $assertion, $currentRow);
            }
            
            if ($developerkey == true) {
                $bad_headers = array(
                    'Content-Type: application/json',
                    'developerkey: junk'
                );
                get_api($ini_array['<endpoint>'], $api_path, 'Bad developerkey', $bad_headers, $assertion, $currentRow);
            }
            
            if ($apikey == true) {
                $bad_headers = array(
                    'Content-Type: application/json',
                    'apikey: junk'
                );
                get_api($ini_array['<endpoint>'], $api_path, 'Bad apikey', $bad_headers, $assertion, $currentRow);
            }
            
            if ($token == true) {
                $bad_headers = array(
                    'Content-Type: application/json',
                    'token: junk'
                );
                get_api($ini_array['<endpoint>'], $api_path, 'Bad token', $bad_headers, $assertion, $currentRow);
            }
        }
        
        if ($ini_array['<scope>'] == "all" || $ini_array['<scope>'] == "headers") {
            // bad path variables
            $bad_path = $path;
            if (isset($path_variables) && count($path_variables) > 0) {
                foreach ($path_variables as $key => $value) {
                    $bad_path = str_replace($value, "junk", $path);
                }
                
                get_api($ini_array['<endpoint>'], $bad_path, 'Bad path variable', $api_headers, $assertion, $currentRow);
            }
        }
        
        // make the good call
        $api_response = get_api($ini_array['<endpoint>'], $api_path, 'Good API call', $api_headers, $assertion, $currentRow);
        
        // POST processing
    } else if ($method == "POST" || $method == "DELETE" || $method == "UPLOAD") {
        
        if ($ini_array['<scope>'] == "all" || $ini_array['<scope>'] == "headers") {
            
            if ($apikey == true || $developerkey == true) {
                // test bad keys
                $bad_headers = array(
                    'Content-Type: application/json'
                );
                
                if ($method == "POST") {
                    $api_response = post_api($ini_array['<endpoint>'], $api_path, $api_data, 'No keys', $bad_headers, $assertion, $currentRow);
                } else if ($method == "UPLOAD") {
                    $api_response = upload_api($ini_array['<endpoint>'], $api_path, $bad_data, 'No keys', $api_headers, $row[$UPLOAD_INDEX], $assertion, $currentRow);
                } else {
                    $api_response = delete_api($ini_array['<endpoint>'], $api_path, $api_data, 'No keys', $bad_headers, $assertion, $currentRow);
                }
            }
            
            if ($developerkey == true) {
                $bad_headers = array(
                    'Content-Type: application/json',
                    'developerkey: junk'
                );
                if ($method == "POST") {
                    $api_response = post_api($ini_array['<endpoint>'], $api_path, $api_data, 'Bad key', $bad_headers, $assertion, $currentRow);
                } else if ($method == "UPLOAD") {
                    $api_response = upload_api($ini_array['<endpoint>'], $api_path, $bad_data, 'Bad key', $api_headers, $row[$UPLOAD_INDEX], $assertion, $currentRow);
                } else {
                    $api_response = delete_api($ini_array['<endpoint>'], $api_path, $api_data, 'Bad key', $bad_headers, $assertion, $currentRow);
                }
            }
            
            if ($apikey == true) {
                $bad_headers = array(
                    'Content-Type: application/json',
                    'apikey: junk'
                );
                
                if ($method == "POST") {
                    $api_response = post_api($ini_array['<endpoint>'], $api_path, $api_data, 'Bad apikey', $bad_headers, $assertion, $currentRow);
                } else if ($method == "UPLOAD") {
                    $api_response = upload_api($ini_array['<endpoint>'], $api_path, $bad_data, 'Bad apikey', $api_headers, $row[$UPLOAD_INDEX], $assertion, $currentRow);
                } else {
                    $api_response = delete_api($ini_array['<endpoint>'], $api_path, $api_data, 'Bad apikey', $bad_headers, $assertion, $currentRow);
                }
            }
            // echo "post ini array:".print_r($ini_array,true);
            
            if ($token == true) {
                $bad_headers = array(
                    'Content-Type: application/json',
                    'token: junk'
                );
                
                if ($method == "POST") {
                    $api_response = post_api($ini_array['<endpoint>'], $api_path, $api_data, 'Bad token', $bad_headers, $assertion, $currentRow);
                } else if ($method == "UPLOAD") {
                    $api_response = upload_api($ini_array['<endpoint>'], $api_path, $bad_data, 'Bad token', $api_headers, $row[$UPLOAD_INDEX], $assertion, $currentRow);
                } else {
                    $api_response = delete_api($ini_array['<endpoint>'], $api_path, $api_data, 'Bad token', $bad_headers, $assertion, $currentRow);
                }
            }
        }
        
        if ($ini_array['<scope>'] == "all" || $ini_array['<scope>'] == "body") {
            
            report_detail("body :" . $body);
            
            // bad data tests
            if (isset($body_args) && count($body_args) > 0) {
                
                foreach ($body_args as $key => $value) {
                    
                    // echo "outer key:" . $key . " value:" . $value . "\n";
                    
                    if (startsWith($value, "<") && endsWith($value, ">") && isset($ini_array[$value])) {
                        
                        $bad_data = array();
                        foreach ($body_args as $key_add => $value_add) {
                            
                            // echo "inner key:".$key_add." value:".$value_add."\n";
                            
                            if ($key == $key_add) {
                                // junk it up
                                $bad_data[$key_add] = "junk";
                                // echo "set junk"."\n";
                            } else {
                                // add orig
                                $bad_data[$key_add] = $ini_array[$value_add];
                                // echo "set original"."\n";
                            }
                        }
                        
                        // echo "bad data:".print_r($bad_data,true)."\n";
                        
                        if ($method == "POST") {
                            $api_response = post_api($ini_array['<endpoint>'], $api_path, $bad_data, 'Bad body param - ' . $key, $api_headers, $assertion, $currentRow);
                        } else if ($method == "UPLOAD") {
                            $api_response = upload_api($ini_array['<endpoint>'], $api_path, $bad_data, 'Bad body param - ' . $key, $api_headers, $row[$UPLOAD_INDEX], $assertion, $currentRow);
                        } else {
                            $api_response = delete_api($ini_array['<endpoint>'], $api_path, $bad_data, 'Bad body param - ' . $key, $api_headers, $assertion, $currentRow);
                        }
                    } else {
                        
                        // echo "nothing done for key:" . $key . " value:" . $value . "\n";
                    }
                }
            }
        }
        
        // echo "api good data:" . print_r($api_data, true) . "\n";
        // echo "api good header:" . print_r($api_headers, true) . "\n";
        
        // good response
        if ($method == "POST") {
            $api_response = post_api($ini_array['<endpoint>'], $api_path, $api_data, 'Good API call', $api_headers, $assertion, $currentRow);
        } else if ($method == "UPLOAD") {
            $api_response = upload_api($ini_array['<endpoint>'], $api_path, $api_data, 'Good API call', $api_headers, $row[$UPLOAD_INDEX], $assertion, $currentRow);
        } else {
            $api_response = delete_api($ini_array['<endpoint>'], $api_path, $api_data, 'Good API call', $api_headers, $assertion, $currentRow);
        }
        
        // other processing
    } else if ($method == "DOWNLOAD") {
        
        if (strpos($row[$PATH_INDEX], "http") !== FALSE) {
            $url = $row[$PATH_INDEX];
        } else {
            $url = $ini_array[$row[$PATH_INDEX]];
        }
        
        // echo print_r($ini_array,true);
        
        $content = file_get_contents($url);
        
        report_detail("download :" . strlen($content) . " from " . $url);
        
        if (strpos($content, $assertion) > 0) {
            $GLOBALS['success_count'] = $GLOBALS['success_count'] + 1;
        } else {
            $GLOBALS['failure_count'] = $GLOBALS['failure_count'] + 1;
            $new_array = array(
                "Failed URI[" . strlen($content) . "/" . $assertion . "]:" . $uri . " reason:content missing"
            );
            $GLOBALS['failedcalls'] = array_merge($GLOBALS['failedcalls'], $new_array);
            // echo ">>> failed URI[".intval($httpcode)."/".$assertion."]:" . $uri. " reason:".$result."\n";
        }
        $GLOBALS['api_count'] = $GLOBALS['api_count'] + 1;
    }
    
    if (isset($api_response['status']) && isset($api_response['message'])) {
        
        $decoded_response = json_decode($api_response['message'], true);
        
        if (isset($GLOBALS['detail']) && $GLOBALS['detail'] == "results") {
            echo $api_response['message'] . "\n";
        }
        
        // echo "dc1:".print_r($decoded_response,true)."\n";
        
        // process actions
        if (isset($actions) && count($actions) > 0) {
            
            foreach ($actions as $key => $value) {
                
                $nvp = explode("=", $value);
                
                if (count($nvp) > 1) {
                    $message_index = str_replace("<", "", $nvp[1]);
                    $message_index = str_replace(">", "", $message_index);
                    
                    // echo "dc2:".print_r($decoded_response,true);
                    
                    $ini_array["<" . $nvp[0] . ">"] = $decoded_response[$message_index];
                    report_detail("save:<" . $nvp[0] . ">:" . $decoded_response[$message_index]);
                }
            }
        }
    } else {
        report_detail("api not right:" . print_r($api_response, true));
    }
    
    if ($ini_array['<scope>'] == "all") {
        echo "Error responses ...\n";
        echo print_r($GLOBALS['responses'], true) . "\n";
    }
    
    $GLOBALS['responses'] = array();
}

if (! isset($GLOBALS['quiet'])) {
    if ($api_count > 0) {
        $passRate = (($success_count * 100.0) / $api_count);
        echo "DONE: " . number_format($passRate, 1) . "% success for " . $options["l"] . "\n\n";
    } else {
        echo "DONE: No APIs called " . "\n\n";
    }
    
    if (count($GLOBALS['failedcalls']) > 0) {
        echo "Failed responses ...\n";
        echo print_r($GLOBALS['failedcalls'], true) . "\n";
    }
} else {
    if ($api_count > 0) {
        $passRate = (($success_count * 100.0) / $api_count);
        $message = "DONE: " . number_format($passRate, 1) . "% success for " . $options["l"] . "\n\n";
        $myfile = file_put_contents($GLOBALS['label'] . ".out", $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    } else {
        $message = "DONE: No APIs called " . "\n\n";
        $myfile = file_put_contents($GLOBALS['label'] . ".out", $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    
    if (count($GLOBALS['failedcalls']) > 0) {
        $message = "Failed responses ...\n" . print_r($GLOBALS['failedcalls'], true) . "\n";
        $myfile = file_put_contents($GLOBALS['label'] . ".out", $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    
}

exit();

function startsWith($haystack, $needle)
{
    // echo "haystack:".print_r($haystack,true)."\n";
    $length = strlen($needle);
    return (substr($haystack, 0, $length) === $needle);
}

function endsWith($haystack, $needle)
{
    $length = strlen($needle);
    
    return $length === 0 || (substr($haystack, - $length) === $needle);
}

function get_api($basepath, $uri, $description, $headers, $assertion, $line)
{
    report_detail("API $description ...");
    report_detail("    GET $basepath$uri");
    
    $ch = curl_init($basepath . $uri);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    report_detail("    [$httpcode]: $result\n");
    
    if (intval($httpcode) == $assertion) {
        $GLOBALS['success_count'] = $GLOBALS['success_count'] + 1;
        
        report_status("GET", "PASS", $httpcode, $uri, "");
    } else if (intval($httpcode) != $assertion) {
        $GLOBALS['failure_count'] = $GLOBALS['failure_count'] + 1;
        $new_array = array(
            "Failed URI on line " . $line . " [" . intval($httpcode) . "/" . $assertion . "]:" . $uri . " reason:" . $result
        );
        $GLOBALS['failedcalls'] = array_merge($GLOBALS['failedcalls'], $new_array);
        // echo ">>> failed URI[".intval($httpcode)."/".$assertion."]:" . $uri. " reason:".$result."\n";
        
        report_status("GET", "FAIL", $httpcode, $uri, "assertion not " . $assertion);
    } else if (intval($httpcode) >= 400) {
        $GLOBALS['failure_count'] = $GLOBALS['failure_count'] + 1;
        
        report_status("GET", "FAIL", $httpcode, $uri, "unexpected status");
    } else {}
    
    $GLOBALS['api_count'] = $GLOBALS['api_count'] + 1;
    
    if (strpos($result, "reason") > 1) {
        
        $item = json_decode($result, true);
        
        if (is_array($item)) {
            
            $new_array = array(
                " " . $httpcode . " " . $item['reason'] => 'true'
            );
            $GLOBALS['responses'] = array_merge($GLOBALS['responses'], $new_array);
        } else {
            
            $new_array = array(
                " " . $httpcode . " " . $result => 'true'
            );
            $GLOBALS['responses'] = array_merge($GLOBALS['responses'], $new_array);
        }
    }
    
    return array(
        "status" => $httpcode,
        "message" => $result
    );
}

function post_api($basepath, $uri, $data, $description, $headers, $assertion, $line)
{
    report_detail("API $description ...");
    report_detail("    POST $basepath$uri Body:" . print_r($data, true));
    
    $ch = curl_init($basepath . $uri);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    if (isset($data) && count($data) > 0) {
        $data_string = json_encode($data);
        // echo "data string:".$data_string;
        array_push($headers, 'Content-Length: ' . strlen($data_string));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // echo "headers:".print_r($headers, true)."\n";
    
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    report_detail("    [$httpcode]: $result\n");
    
    if (intval($httpcode) == $assertion) {
        $GLOBALS['success_count'] = $GLOBALS['success_count'] + 1;
        
        report_status("POST", "PASS", $httpcode, $uri, "");
    } else if (intval($httpcode) != $assertion) {
        $GLOBALS['failure_count'] = $GLOBALS['failure_count'] + 1;
        $new_array = array(
            "Failed URI on line " . $line . " [" . intval($httpcode) . "/" . $assertion . "]:" . $uri . " reason:" . $result
        );
        $GLOBALS['failedcalls'] = array_merge($GLOBALS['failedcalls'], $new_array);
        
        report_status("POST", "FAIL", $httpcode, $uri, "assertion not " . $assertion);
        
        // echo ">>> failed URI[".intval($httpcode)."/".$assertion."]:" . $uri. " reason:".$result."\n";
    } else if (intval($httpcode) >= 400) {
        $GLOBALS['failure_count'] = $GLOBALS['failure_count'] + 1;
        
        report_status("POST", "FAIL", $httpcode, $uri, "unexpected status");
    }
    $GLOBALS['api_count'] = $GLOBALS['api_count'] + 1;
    
    if (strpos($result, "reason") > 1) {
        
        $item = json_decode($result, true);
        
        if (is_array($item)) {
            
            $new_array = array(
                " " . $httpcode . " " . $item['reason'] => 'true'
            );
            $GLOBALS['responses'] = array_merge($GLOBALS['responses'], $new_array);
        } else {
            
            $new_array = array(
                " " . $httpcode . " " . $result => 'true'
            );
            $GLOBALS['responses'] = array_merge($GLOBALS['responses'], $new_array);
        }
    }
    
    return array(
        "status" => $httpcode,
        "message" => $result
    );
}

function delete_api($basepath, $uri, $data, $description, $headers, $assertion, $line)
{
    report_detail("API $description ...");
    report_detail("    DELETE $basepath$uri Body:" . print_r($data, true));
    
    $ch = curl_init($basepath . $uri);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    if (isset($data) && count($data) > 0) {
        
        // echo "add data\n";
        
        $data_string = json_encode($data);
        array_push($headers, 'Content-Length: ' . strlen($data_string));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    report_detail("    [$httpcode]: $result\n");
    
    if (intval($httpcode) == $assertion) {
        $GLOBALS['success_count'] = $GLOBALS['success_count'] + 1;
        
        report_status("DELETE", "PASS", $httpcode, $uri, "");
    } else if (intval($httpcode) != $assertion) {
        $GLOBALS['failure_count'] = $GLOBALS['failure_count'] + 1;
        $new_array = array(
            "Failed URI on line " . $line . " [" . intval($httpcode) . "/" . $assertion . "]:" . $uri . " reason:" . $result
        );
        $GLOBALS['failedcalls'] = array_merge($GLOBALS['failedcalls'], $new_array);
        
        report_status("DELETE", "FAIL", $httpcode, $uri, "assertion not " . $assertion);
        
        // echo ">>> failed URI[".intval($httpcode)."/".$assertion."]:" . $uri. " reason:".$result."\n";
    } else if (intval($httpcode) >= 400) {
        $GLOBALS['failure_count'] = $GLOBALS['failure_count'] + 1;
        
        report_status("DELETE", "FAIL", $httpcode, $uri, "unexpected status");
    }
    $GLOBALS['api_count'] = $GLOBALS['api_count'] + 1;
    
    if (strpos($result, "reason") > 1) {
        
        $item = json_decode($result, true);
        
        if (is_array($item)) {
            
            $new_array = array(
                " " . $httpcode . " " . $item['reason'] => 'true'
            );
            $GLOBALS['responses'] = array_merge($GLOBALS['responses'], $new_array);
        } else {
            
            $new_array = array(
                " " . $httpcode . " " . $result => 'true'
            );
            $GLOBALS['responses'] = array_merge($GLOBALS['responses'], $new_array);
        }
    }
    
    return array(
        "status" => $httpcode,
        "message" => $result
    );
}

function upload_api($basepath, $uri, $data, $description, $headers, $fileinfo, $assertion, $line)
{
    
    // data fields for POST request
    /*
     * $fields = array(
     * "name" => "Test API Bulk",
     * "productid" => "80BC820D-CFD6-3C4E-563E-FC4855CF1302"
     * );
     */
    
    // echo "data:".print_r($data,true)."\n";
    $fields = array();
    if (isset($data) && count($data) > 0) {
        // $data_string = json_encode($data);
        // echo "data string:".$data_string;
        $fields = $data;
    }
    
    // files to upload
    // $filecontent = file_get_contents($filename);
    $file_items = explode("=", $fileinfo);
    $upload_part = $file_items[0];
    $filename = $file_items[1];
    
    $handle = fopen($filename, "r");
    $filecontent = fread($handle, filesize($filename));
    fclose($handle);
    
    // echo "file:".filesize($filename)."\n";
    
    // URL to upload to
    $url = $basepath . $uri;
    $boundary = "5959_API_TESTER_5959";
    
    $ch = curl_init();
    
    $post_data = build_multi_parts($boundary, $fields, $filecontent, $filename, $upload_part);
    
    report_detail("post:" . print_r($post_data, true));
    
    $delimiter = '-------------' . $boundary;
    
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_CUSTOMREQUEST => "POST",
        // CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $post_data
    ));
    
    /*
     * CURLOPT_HTTPHEADER => array(
     * 'token: ' . $token,
     * 'developerkey: RUU0RENBMzQtRTdGRS00MjUyLUFCQjItNDZGRTMxMTkxQTM2',
     * 'Content-Type: multipart/form-data; boundary=' . $delimiter,
     * 'Content-Length: ' . strlen($post_data)
     * )
     */
    
    array_push($headers, 'Content-Type: multipart/form-data; boundary=' . $delimiter);
    array_push($headers, 'Content-Length: ' . strlen($post_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    report_detail("headers:" . print_r($headers, true));
    
    $result = curl_exec($ch);
    
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    report_detail("    [$httpcode]: $result\n");
    
    if (intval($httpcode) == $assertion) {
        $GLOBALS['success_count'] = $GLOBALS['success_count'] + 1;
        
        report_status("UPLOAD", "PASS", $httpcode, $uri, "");
    } else if (intval($httpcode) != $assertion) {
        $GLOBALS['failure_count'] = $GLOBALS['failure_count'] + 1;
        $new_array = array(
            "Failed URI on line " . $line . " [" . intval($httpcode) . "/" . $assertion . "]:" . $uri . " reason:" . $result
        );
        $GLOBALS['failedcalls'] = array_merge($GLOBALS['failedcalls'], $new_array);
        
        report_status("UPLOAD", "FAIL", $httpcode, $uri, "assertion not " . $assertion);
        
        // echo ">>> failed URI[".intval($httpcode)."/".$assertion."]:" . $uri. " reason:".$result."\n";
    } else if (intval($httpcode) >= 400) {
        $GLOBALS['failure_count'] = $GLOBALS['failure_count'] + 1;
        
        report_status("UPLOAD", "FAIL", $httpcode, $uri, "unexpected status");
    }
    $GLOBALS['api_count'] = $GLOBALS['api_count'] + 1;
    
    if (strpos($result, "reason") > 1) {
        
        $item = json_decode($result, true);
        
        if (is_array($item)) {
            
            $new_array = array(
                " " . $httpcode . " " . $item['reason'] => 'true'
            );
            $GLOBALS['responses'] = array_merge($GLOBALS['responses'], $new_array);
        } else {
            
            $new_array = array(
                " " . $httpcode . " " . $result => 'true'
            );
            $GLOBALS['responses'] = array_merge($GLOBALS['responses'], $new_array);
        }
    }
    
    return array(
        "status" => $httpcode,
        "message" => $result
    );
}

function build_multi_parts($boundary, $fields, $filecontent, $filename, $upload_part)
{
    $data = '';
    $eol = "\r\n";
    
    $delimiter = '-------------' . $boundary;
    
    // Add file content
    $data .= "--" . $delimiter . $eol . 'Content-Disposition: form-data; name="' . $upload_part . '"; filename="' . $filename . '"' . $eol . 'Content-Type: application/octet-stream' . $eol;
    
    $data .= $eol;
    $data .= $filecontent . $eol;
    
    foreach ($fields as $name => $content) {
        $data .= "--" . $delimiter . $eol . 'Content-Disposition: form-data; name="' . $name . "\"" . $eol . $eol . $content . $eol;
    }
    
    $data .= "--" . $delimiter . "--" . $eol;
    
    return $data;
}

function report_detail($message, $result = false)
{
    if (! isset($GLOBALS['quiet'])) {
        if (($GLOBALS['detail'] != "summary" && $GLOBALS['detail'] != "results") || $result) {
            echo $message . "\n";
        }
    } else {
        $myfile = file_put_contents($GLOBALS['label'] . ".out", $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function report_status($method, $status, $httpcode, $uri, $message)
{
    $message = $status . " [" . $httpcode . "]: " . $method . " " . $uri . " " . $message . "\n";
    if (! isset($GLOBALS['quiet'])) {
        echo $message;
    } else {
        $myfile = file_put_contents($GLOBALS['label'] . ".out", $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function write_php_ini($array, $file)
{
    $res = array();
    foreach ($array as $key => $val) {
        if (is_array($val)) {
            $res[] = "[$key]";
            foreach ($val as $skey => $sval)
                $res[] = "$skey = " . (is_numeric($sval) ? $sval : '"' . $sval . '"');
        } else
            $res[] = "$key = " . (is_numeric($val) ? $val : '"' . $val . '"');
    }
    safefilerewrite($file, implode("\r\n", $res));
}

function safefilerewrite($fileName, $dataToSave)
{
    if ($fp = fopen($fileName, 'w')) {
        $startTime = microtime(TRUE);
        do {
            $canWrite = flock($fp, LOCK_EX);
            // If lock not obtained sleep for 0 - 100 milliseconds, to avoid collision and CPU load
            if (! $canWrite)
                usleep(round(rand(0, 100) * 1000));
        } while ((! $canWrite) and ((microtime(TRUE) - $startTime) < 5));
        
        // file was locked so now we can store information
        if ($canWrite) {
            fwrite($fp, $dataToSave);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}
?>
