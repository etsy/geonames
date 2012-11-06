#!/usr/bin/php
<?php

ini_set('memory_limit', '-1');


class CreateMapping {

    public $placesuggestfile;
    public $fullSuccess;
    public $partialSuccess;
    public $failure;
    public $counts;
    public $mapping;

    public function __construct() {
        //Tab seperated source data
        $this->placesuggestfile = "source_data.tsv";
        //Mysql database containing geonames data
        $this->aux = mysql_connect('server:port', 'user', 'password');
        mysql_select_db('geoname', $this->aux);
        mysql_set_charset('utf8');

        //files and counters for output tracking
        $this->fullSuccess = fopen("match.txt", "w");
        $this->partialSuccess = fopen("multiple_match.txt", "w");
        $this->failure = fopen("failure.txt", "w");
        $this->mapping = fopen("mapping.tsv", "w");
        $this->counts = array();
        $this->counts["total"] = 0;
        $this->counts["success"] = 0;
        $this->counts["multiple"] = 0;
        $this->counts["failure"] = 0;
        $this->counts["missingCity"] = 0;

    }

    public function printMapLine($data, $terms) {
        $string = trim($terms[0]) . ", " . trim($terms[1]) . ", " . trim($terms[2]) . "\t" . trim($data[2]) . "\n";
        fwrite($this->mapping, $string);
    }

    public function printOutputLine($handle, $data, $distance) {
        $string = "$distance - {$data[0]} {$data[1]} - {$data[2]}\n";
        fwrite($handle, $string);
    }
    public function addCustomMappings() {
        $map = array();
        //$map["new york, new york, united states"] = 5125771;
        foreach ($map as $city=>$id) {
            $string = "$city\t$id";
            fwrite($this->mapping, $string);
        }
    }

    public function parseDataFile() {
        //$fields[0] == name
        //$fields[1] == region
        //$fields[2] == country name
        //$fields[3] == country code
        //$fields[4] == latitude
        //$fields[5] == longitude
        //$fields[6] == population - not used

        $importHandle = fopen($this->placesuggestfile, "r");
        if ($importHandle) {
            while (( $buffer = fgets($importHandle, 4096)) !== false) {
                $fields = str_getcsv($buffer, "\t", "\0");
                if (count($fields) != 7) {
                    print_r($fields);
                    print "FIELD FAILURE\n";
                    $locationMatch = null;
                } else {

                    $locationMatch = $this->findLocationMatch(
                        $fields[0], $fields[1], $fields[2],
                        $fields[3], $fields[4], $fields[5], $fields[0]
                    );
                    if (is_null($locationMatch) && $fields[0] != "") {
                        $locationMatch = $this->findLocationMatch(
                            $fields[0] . "%", $fields[1], $fields[2],
                            $fields[3], $fields[4], $fields[5], $fields[0]
                        );
                    }
                    if (is_null($locationMatch) && $fields[0] != "" && strlen($fields[0]) >  4) {
                        if (strlen($fields[0]) < 10) {
                            $len = round(strlen($fields[0]) * .5, 0);
                        } else if (strlen($fields[0]) > 30) {
                            $len = round(strlen($fields[0]) * .2, 0);
                        } else {
                            $len = round(strlen($fields[0]) * .3, 0);
                        }

                        $locationMatch = $this->findLocationMatch(
                            substr($fields[0], 0, $len) . "%", $fields[1], $fields[2],
                            $fields[3], $fields[4], $fields[5], $fields[0]
                        );
                    }
                }

                if (is_null($locationMatch)) {
                    if ($fields[0] == "" || is_null($fields)) {
                        $this->counts["missingCity"] += 1;
                    }
                    $string = implode(" ", $fields);
                    fwrite($this->failure, $string . "\n");
                    $this->counts["total"] += 1;
                    $this->counts["failure"] += 1;
                    $locationMatch = $this->findNearestInCountryLocation(
                        $fields[3], $fields[4], $fields[5], array($fields[0], $fields[1], $fields[2])
                        );
                    //get nearest in country location instead


                }

                if (!is_null($locationMatch)) {
                    foreach ($locationMatch as $dist=>$values) {
                        if ($dist < 2000000000 || count($locationMatch) == 1) {
                            print "FOUND < 20km:\t{$values[0]} $dist for $buffer";
                            break;
                        } else {
                            print "FOUND > 20km:\t{$values[0]} $dist for $buffer";
                        }
                    }
                } else {
                    print "FAILED:\t$buffer";
                }
            }
        }
    }

    public function findNearestInCountryLocation($countryCode, $latitude, $longitude, $placeArray) {
            if ($placeArray[0]) {
                $query = sprintf(
                         "SELECT geonameid, name, latitude, longitude, population FROM geoname WHERE " .
                         "country = '%s' AND fclass='P' and latitude >= %f and latitude <= %f " .
                         "and longitude >= %f and longitude <= %f",
                         mysql_real_escape_string(strtolower($countryCode)), $latitude - .4,
                         $latitude + .4, $longitude - .4, $longitude + .4
                         );
                print $query . "\n";
                $result = mysql_query($query);
                if (!$result) {
                    $message = 'Invalid query: ' . mysql_error() . "\n";
                    $message .= 'Whole query: ' . $query;
                    die($message);
                }
                $results = array();
                while ($item = mysql_fetch_array($result)) {
                    $results[] = $item;
                }
                if (count($result)) {
                    return $this->rankDistance($results, $latitude, $longitude, $countryCode, $placeArray);
                }
            }
            return null;
    }

    public function findNonUsLocationMatch($city, $region, $country, $countryCode,
                                           $latitude, $longitude, $original_city) {
        if (is_null($city) || $city == "") {
            $city = $region;
        }
        $query = sprintf(
            "SELECT geonameid, name, latitude, longitude, population FROM geoname WHERE name like '%s' " .
            "AND country = '%s' AND fclass='P' ORDER BY population DESC",
            mysql_real_escape_string(strtolower($city)), mysql_real_escape_string(strtolower($countryCode))
        );
        $result = mysql_query($query);
        if (!$result) {
             $message  = 'Invalid query: ' . mysql_error() . "\n";
             $message .= 'Whole query: ' . $query;
             die($message);
        }
        $results = array();
        while ($item = mysql_fetch_array($result)) {
            $results[] = $item;
        }
        if (count($results)) {
            return $this->rankDistance($results, $latitude, $longitude, $countryCode,
                                        array($original_city, $region, $country));
        } else {
            return $this->findNonUsLocationMatchUsingAlt($city, $region, $country, $countryCode,
                                                        $latitude, $longitude, $original_city);
        }
    }

    public function findNonUsLocationMatchUsingAlt($city, $region, $country, $countryCode,
                                                $latitude, $longitude, $original_city) {

        if (is_null($city) || $city == "") {
            $city = $region;
        }
        $query = sprintf(
            "SELECT geonameid, name, latitude, longitude, population FROM geoname WHERE alternatenames like '%%%s%%' " .
            "AND country = '%s' AND fclass='P' ORDER BY population DESC",
            mysql_real_escape_string(strtolower($city)), mysql_real_escape_string(strtolower($countryCode))
        );
        print "Trying alternatenames query: $query\n";
        $result = mysql_query($query);
        if (!$result) {
             $message  = 'Invalid query: ' . mysql_error() . "\n";
             $message .= 'Whole query: ' . $query;
             die($message);
        }
        $results = array();
        while ($item = mysql_fetch_array($result)) {
            $results[] = $item;
        }
        if (count($results)) {
            return $this->rankDistance($results, $latitude, $longitude, $countryCode,
                                        array($original_city, $region, $country));
        } else {
            return null;
        }
    }

    public function findLocationMatch($city, $region, $country, $countryCode, $latitude, $longitude, $original_city) {
        //Puerto rican override;
        if ($countryCode == "us" && $region == "puerto rico") {
            $region = "";
            $countryCode = "pr";
            $country = "puerto rico";
        }
        //Now Puerto Rico will not fire for the us and should map

        if ($countryCode != "us") {
            $places = $this->findNonUSLocationMatch($city, $region, $country, $countryCode,
                                                    $latitude, $longitude, $original_city);
            return $places;
        } else {
            $stateQuery = sprintf(
                "SELECT code FROM admin1Codes WHERE name = '%s'",
                mysql_real_escape_string($region)
            );
            $result = mysql_query($stateQuery);
            if (!$result) {
                 $message  = 'Invalid query: ' . mysql_error() . "\n";
                 $message .= 'Whole query: ' . $query;
                 die($message);
            }
            $stateRes = array();
            while ($item = mysql_fetch_array($result)) {
                $stateRes[] = $item;
            }
            if (count($stateRes) ) {
                $stateArray = explode(".", $stateRes[0][0]);
                $state = $stateArray[1];
                $query = sprintf(
                    "SELECT geonameid, name, latitude, longitude, population FROM geoname WHERE name like '%s' " .
                    "AND admin1 = '%s' AND country = '%s' and fclass='P'",
                    mysql_real_escape_string($city),
                    mysql_real_escape_string($state),
                    mysql_real_escape_string($countryCode)
                );
                $result = mysql_query($query);
                if (!$result) {
                     $message  = 'Invalid query: ' . mysql_error() . "\n";
                     $message .= 'Whole query: ' . $query;
                     die($message);
                }
                $results = array();
                while ($item = mysql_fetch_array($result)) {
                    $results[] = $item;
                }
                if (count($results)) {
                    return $this->rankDistance($results, $latitude, $longitude, $countryCode,
                                                array($original_city, $region, $country));
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }
    }

    //More complex distance formula - used
    function distance($lat1, $lon1, $lat2, $lon2) {
      $theta = (double)$lon1 - (double)$lon2;
      $dist = sin(deg2rad((double)$lat1)) * sin(deg2rad((double)$lat2)) +  cos(deg2rad((double)$lat1))
                * cos(deg2rad((double)$lat2)) * cos(deg2rad((double)$theta));
      $dist = acos($dist);
      $dist = rad2deg($dist);
      $miles = $dist * 60 * 1.1515;

      return ($miles * 1.609344);
    }

    //Simple distance formula for distace between 2 lat/lon pairs - not used
    public function calculateDistance($newLat, $newLon, $oldLat, $oldLon) {
        $lat1 = (float)$newLat/57.2958;
        $lat2 = (float)$oldLat/57.2958;
        $lon1 = (float)$newLon/57.2958;
        $lon2 = (float)$oldLon/57.2958;
        $distance = round(acos(sin($lat1) * sin($lat2) + cos($lat1) * cos($lat2) * cos($lon2 - $lon1)));
        return $distance;
    }

    public function rankDistance($results, $latitude, $longitude, $countryCode, $terms) {
        $places = array();
        if (count($results) == 1) {
            $distance = $this->distance(
                $results[0]['latitude'], $results[0]['longitude'],
                $latitude, $longitude
            );
            $this->printOutputLine(
                $this->fullSuccess, array($results[0]['name'], $countryCode,
                $results[0]['geonameid']), $distance
            );
            $this->printMapLine(array($results[0]['name'], $countryCode, $results[0]['geonameid']), $terms);
            $this->counts["total"] += 1;
            $this->counts["success"] += 1;
            $places[$distance] = array($results[0]['name'], $countryCode, $results[0]['geonameid']);
        } else {
            foreach ($results as $result) {
                $distance = $this->distance($result['latitude'], $result['longitude'], $latitude, $longitude);
                //To 'index' by population, divide by the population (or 1)
                // we then have to multiply by 10000000 since php indexes arrays on ints so we need real numbers
                $mod_distance = $distance /  (1 + $result['population']) * 100000000;
                //print "distance: $distance, $mod_distance, {$result['name']} {$result['population']}\n";
                $places[$mod_distance] = array($result['name'], $countryCode, $result['geonameid'], $distance);
            }
            ksort($places, SORT_NUMERIC);
            foreach ($places as $distance=>$place) {
                // If we found a place closer than 20km (20 * 100000000) call it a match
                if ($place[3]< 2000000000) {
                    $this->printOutputLine($this->fullSuccess, $place, $distance);
                    $this->printMapLine(array($place[0], $place[1], $place[2]), $terms);
                    $this->counts["success"] += 1;
                    $this->counts["total"] += 1;
                } else {
                    $this->printOutputLine($this->partialSuccess, $place, $distance);
                    $this->counts["multiple"] += 1;
                    $this->counts["total"] += 1;
                }
                break;
            }
        }
        return $places;
    }
}

$test = new CreateMapping();
$test->parseDataFile();
$test->addCustomMappings();
$percentage = round(($test->counts["success"] + $test->counts["multiple"]) / $test->counts["total"], 4);
$percentageNoCity = round(
    ($test->counts["success"] + $test->counts["multiple"])
    / ($test->counts["total"] - $test->counts["missingCity"]), 4
);

print "\nTotal {$test->counts["total"]} : Success {$test->counts["success"]} : " .
      "Multiple {$test->counts["multiple"]} : Failure {$test->counts["failure"]} : " .
      "Missing City {$test->counts["missingCity"]} : ".
      "% {$percentage} : % No City {$percentageNoCity}\n\n";
