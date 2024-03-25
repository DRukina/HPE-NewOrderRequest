<?php

// dependencies
require 'config.php';
require 'inc/util.php';
require 'inc/rfb/get.php';
require 'inc/rfb/set.php';
require "../inc/_util.php";
require "../inc/_auth.php";
require "../inc/_bear.php";

require 'inc/emdmh/get.php';
require "inc/payload.php";
require "inc/health.php";
require "inc/logger.php";

$startTime = floor(microtime(true) * 1000);
$response = null;

/** 
 * fetch method 
 */
function fetch($url, $filter)
{
  $curl = curl_init();
  curl_setopt_array(
    $curl,
    array(
      CURLOPT_URL => $url,
      CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_SSL_VERIFYPEER => 0,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 300,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_POSTFIELDS => "pageSize=300&filter=" . json_encode($filter),
      CURLOPT_HTTPHEADER => array(
        "Authorization: Bearer " . bearer(),
        "cache-control: no-cache"
      )
    )
  );

  $response = curl_exec($curl);
  $error = curl_error($curl);
  curl_close($curl);

  if ($error || !isJson($response))
    return false;
  return json_decode($response);
}

// get specific url
// depending on dataset requested
function v3url($fid, $ds, $verb)
{
  return 'https://localhost/form/data-set-provider/v3/' . $fid . '/' . $ds . '/kendo/data/' . $verb;
}

/**
 * The function get records from 1787 which have the Updated field as false - meaning that EMDMH is not yet updated for them
 */
function getRecordsForCheck()
{
  $filter = array(
    "logic" => "and",
    "filters" => array(
      array(
        "field" => 'Updated',
        "operator" => "eq",
        "value" => 0
      ),
    )
  );
  $records = fetch(v3url('1787', 'EMDMHUpdateChecks', 'search'), $filter);

  return $records;
}

/**
 * The function fetch businessRelationGroup of parner from EMDM by ParnerId and config
 * 
 * @param string $partyId partyId
 * @param object $config $config - config.php
 * 
 * @return object businessRelationGroup - array|null, error - string|null
 */
function fetchBusinessRelationGroup($partyId, $config)
{
  $response = (object) [
    'businessRelationGroup' => null,
    'error' => null,
  ];

  $emdmhSearch = emdmhGet($partyId, $config);
  // for testing Fail staatus
  // $emdmhSearch = null;
  if (!$emdmhSearch) {
    $response->error = output(false, "Reprocessing", "EMDMH Search", 'Failed to execute Partner Search to get BRGA after Partner Creation on the Fly', "", null);
    return $response;
  } else if (
    !isset($emdmhSearch->searchPartnerEmdmh) || !isset($emdmhSearch->searchPartnerEmdmh->searchList) || sizeof($emdmhSearch->searchPartnerEmdmh->searchList) != 1
  ) {
    $response->error = output(false, "Reprocessing", "EMDMH Search", 'Failed to find results from Partner Search to get BRGA after Partner Creation on the Fly: ' . $emdmhSearch->searchPartnerEmdmh->code . ' / ' . sizeof($emdmhSearch->searchPartnerEmdmh->searchList) . ' results', "", null);
    return $response;
  } else {
    $emdmhSearch = $emdmhSearch->searchPartnerEmdmh->searchList[0];
    if (isset($emdmhSearch->businessRelationGroup)) {
      $response->businessRelationGroup = $emdmhSearch->businessRelationGroup;
      return $response;
    } else {
      $response->error = output(false, "Reprocessing", "EMDMH Search", 'Failed to execute Partner Search to get BRGA after Partner Creation on the Fly - codes', "", null);
    }
  }
  return $response;
}

/**
 * The function filter records by PartyId
 * @param array $records records
 * @param string $partyId partyId
 * 
 * @return array $filteredRecords
 */
function filterByPartyId($records, $partyId)
{
  $filteredRecords = array_filter($records, function ($record) use ($partyId) {
    return $record->PartyId == $partyId;
  });
  $filteredRecords = array_values($filteredRecords);
  return $filteredRecords;
}

/**
 * Update rfb method 
 * 
 * @param array $post models
 * @param string $url url
 * @param string $method put
 */
function update($url, $post, $method)
{
  $curl = curl_init();
  curl_setopt_array(
    $curl,
    array(
      CURLOPT_URL => $url,
      CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_SSL_VERIFYPEER => 0,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_POSTFIELDS => array("models" => json_encode($post)),
      CURLOPT_HTTPHEADER => array(
        "Authorization: Bearer " . bearer(),
        "cache-control: no-cache"
      )
    )
  );

  $response = curl_exec($curl);
  $error = curl_error($curl);
  curl_close($curl);

  if ($error || !isJson($response))
    return false;

  return json_decode($response);
}


/**
 * The function update Check data
 * @param array $records
 * @param object $data
 * 
 * @return object $response: data - array|null, error - string|null
 */
function updateCheckRecords($records, $data)
{
  $response = (object) [
    'data' => null,
    'error' => null,
  ];

  if (sizeof($records)) {
    foreach ($records as &$el) {
      $el->Updated = $data->updated;
      if (!$el->CheckCount) {
        $el->CheckCount = 1;
      } else {
        $el->CheckCount = $el->CheckCount + 1;
      }
      if (isset($data->message)) {
        $el->Message = $data->message;
      }
    }

    $rfb = update(v3url('1787', 'EMDMHUpdateChecks', ''), $records, 'PUT');

    if (!$rfb || sizeof($rfb) < 1) {
      $response->error = output(false, "Emdmh Check", "updateCheckRecords", "Unhandled exception; Failed to update the application data row", "", $records);
      return $response;
    }
    // for testing
    // $data->updated = false;
    sendNotification($records, $data->updated);
    $response->data = $rfb;
  }
  return $response;
}

function getNotificationConfig($record, $type = 'ProfilingTeam')
{
  $obj = null;
  $coe = $record->CoE;

  if ($type === 'ProfilingTeam') {
    $epi_codes = (object) [
      'eCommerce' => "PM_ARUBA_SMB_ECOMM",
      'IntegratorReseller' => "PM_ARUBA_SMB_InteResell",
      'Professionalservices' => "COE_ARUBA_PS_CAN", //EPI Code
      'Customersuccess' => "COE_ARUBA_CS_CAN",
    ];
    $coe_code = str_replace(' ', '', $coe);
    $epi = $epi_codes->$coe_code;

    $obj = (object) [
      'To' => $record->WorkEmail,
      'Col2' => $record->CompanyEnglishName,
      'Col1' => $epi, //EPI Code
      'Col3' => $record->PartyId,
      'Col4' => $record->CoE, // ProgramName
      'Col5' => $record->Modified, // Application Date ?? 
      'ID' => 'PE_AC_PT',
    ];

  } else {
    $ID = 'PECA-PA';
    if ($coe === 'Integrator Reseller') {
      $ID = 'PE_IR_CA';
    } else if ($coe === 'Professional services') {
      $ID = 'PE_PS_CA';
    } else if ($coe === 'Customer success') {
      $ID = 'PE_CS_CA';
    }

    $obj = (object) [
      'To' => $record->WorkEmail,
      'Col1' => $record->FirstName . ' ' . $record->LastName,
      'Col2' => $record->EntryId,
      'Col3' => $record->PartyId,
      'Col4' => $record->CompanyEnglishName,
      'Col5' => $record->Region,
      'ID' => $ID,
    ];
  }
  return $obj;
}

function sendNotification($items, $approve = false)
{
  // fetch infortmation about partner

  if (sizeof($items)) {
    foreach ($items as &$el) {
      $entryId = $el->OverviewId;

      $filter = array(
        "logic" => "and",
        "filters" => array(
          array(
            "field" => 'EntryId',
            "operator" => "eq",
            "value" => $entryId,
          ),
        )
      );
      // /form/data-set-provider/v2/1280/XaaS_Enrollments_DEV/kendo/data
      $records = fetch(v3url('1280', 'XaaS_Enrollments_DEV', 'search'), $filter);
      if ($records && isset($records->Data) && sizeof($records->Data)) {
        $records = $records->Data;
        $record = $records[0];

        $obj = null;
        if ($approve) {
          $obj = getNotificationConfig($record, 'Approve');
        } else if (round($el->CheckCount) > 8) {
          $obj = getNotificationConfig($record, 'ProfilingTeam');
        }
        $data = array($obj);
        $notification = update(v3url('690', 'MAILJS', ''), $data, 'POST');
      }
    }
  }
}

function processData($partyId, $filteredRecords, $config)
{
  $response = fetchBusinessRelationGroup($partyId, $config);
  $error = $response->error;
  
  // test
 // $error = '';
  
  if ($error) {
    // emdm error
    $data = (object) [
      'status' => 'Fail',
      'updated' => false,
      'message' => 'Timeout error while waiting making EMDMH request',
    ];
    $response = updateCheckRecords($filteredRecords, $data);
    if ($response->error) {
      return output(false, "Emdmh Check", "Process data", $response->error, "", $response);
    }
    return output(true, "Emdmh Check", "Process data", $error, "", $response->data);
  }
  $isUpdated = false;
  $businessRelationGroup = $response->businessRelationGroup;
  if ($businessRelationGroup && sizeof($businessRelationGroup)) {
    // find an object with extendedProfItem property that has the correct extdProfItemCode - COE_ARUBA_PS, COE_ARUBA_CS depending on the program with extdProfItemStatus "ACTIVE"
    foreach ($businessRelationGroup as &$group) {
      if (isset($group->extendedProfItem) && sizeof($group->extendedProfItem)) {
        $extendedProfItem = $group->extendedProfItem;
        foreach ($extendedProfItem as &$item) {
          if (
            $item->extdProfItemStatus == 'ACTIVE' &&
            ($item->extdProfItemCode == 'PM_ARUBA_SMB_ECOMM' ||
              $item->extdProfItemCode == 'PM_ARUBA_SMB_InteResell' ||
              $item->extdProfItemCode == 'COE_ARUBA_PS_CAN' ||
              $item->extdProfItemCode == 'COE_ARUBA_CS_CAN')
          ) {
            $isUpdated = true;
            break;
          }
        }
      }
    }
    // updated
  }
  // test
 // $isUpdated = true;
  $data = (object) [
    'status' => 'Passed',
    'updated' => $isUpdated,
    'message' => '',
  ];
  $response = updateCheckRecords($filteredRecords, $data);
  if ($response->error) {
    return output(false, "Emdmh Check", "Process data", $response->error, "", $response);
  }
  return output(true, "Emdmh Check", "Process data", "", "", $response->data);
}

function check($config)
{
  $response = null;
  $records = getRecordsForCheck();
  if (!$records || !isset($records->Data)) {
    $response = json_encode(output(false, "Emdmh Check", "Main", "Failed to fetch data from 1787", "", null));
    return $response;
  }
  if ($response == null) {
    $records = $records->Data;
    if ($records && sizeof($records) > 0) {
      // get unique partyIds
      $partyIds = array_map(function ($var) {
        return $var->PartyId;
      }, $records);
      $partyIds = array_unique($partyIds);
      // get info for each partyId
      if (sizeof($partyIds) > 0) {
        $data = array();
        foreach ($partyIds as &$partyId) {
          $filteredRecords = filterByPartyId($records, $partyId);
          $process_response = processData($partyId, $filteredRecords, $config);
          array_push($data, $process_response);
        }
        $response = output(true, "Emdmh Check", "Main", "Success", null, $data);
      }

    } else {
      $response = output(true, "Emdmh Check", "Main", "No records for check", null, null);
    }
  }
  return $response;
}

$response = check($config);

// logging
$tat = floor(microtime(true) * 1000) - $startTime;
$error = null;

if (!$response->Success) {
  $error = $response->Message;
}

// logger 

// final
echo json_encode($response);

?>