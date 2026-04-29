<?php include '../session.php'; 
require_once('GETIECheckProcessor_PPD.php');
$month = $_POST['month'];
$year = $_POST['year'];
$agent = $_POST['agent'];
$_POST['agentId'] = $agent;
$agentsss  =$conn->query("SELECT * FROM tbl_agent WHERE id='".$agent."'")->fetch_assoc(); 
$_POST['AccountNumber'] =$agentsss['account'];
$_POST['RoutingNumber'] = $agentsss['rounting'];
$_POST['CheckNumber'] ='11111';//$agentsss['check_number'];
$_POST['DLState'] ='TN';//$agentsss['dl_state'];
$_POST['DLNumber'] ='12345';//$agentsss['dl_number'];
$_POST['FirstName'] =$agentsss['firstname'];
$_POST['LastName'] =$agentsss['lastname'];
$_POST['Address1'] =$agentsss['address'];
$_POST['Address2'] =$agentsss['address'];
$_POST['City'] =$agentsss['city'];
$_POST['State'] =$agentsss['state'];
$_POST['Zip'] =$agentsss['zip'];
if(!empty($agentsss['contact'])) {
    $PhoneNumber =$agentsss['contact'];
} else if(!empty($agentsss['workphone'])) {
    $PhoneNumber =$agentsss['workphone'];
} else if(!empty($agentsss['homephone'])) {
    $PhoneNumber =$agentsss['homephone'];
}
$_POST['PhoneNumber'] = str_replace("-","",$PhoneNumber);
$_POST['amount'] = '-'.round((float) ($_POST['amount'] ?? 0), 2);
$_POST['action'] = 'process';
$_POST['Identifier'] = "R";
$_POST['type'] = "1814";
$isError = false;
// process the POST request
if (isset($_POST['action']) && $_POST['action'] == 'process') {
	$validForm = true;
	$errMsgs = array();
	$ChargeAmount = trim($_POST['amount']);
// 	if ($ChargeAmount > 0) {
// 		$validForm = true;
// 	} else {
// 		$errMsgs[] = 'No amount to charge';
// 	}
	
	// the require parameters that aren't set in the test function
	$reqParams = array('RoutingNumber', 'Identifier', 'action', 'amount', 'type', 'agentId', 'AccountNumber', 'FirstName', 'LastName', 'Address1', 'Address2', 'City', 'State', 'Zip', 'PhoneNumber');
	$params = array();
	foreach ($reqParams as $key) {
		if (!isset($_POST[$key]) || !strlen(trim($_POST[$key]))) {
			$validForm = false;
			$errMsgs[] = $key;
		}
		
		$params[$key] = $_POST[$key];
	}
	
	if ($validForm) {
	    $processorTest = new ECheckProcessorTest();
		$result = $processorTest->testProcess($params, $ChargeAmount);
	} else {
		$isError = true;
	}
}
	    $array = array();
	if ($isError) {
		$resulterrors = array("isError"=> $isError, "status" => 'failed', "agent" => $agent, "month" => $_POST['month'], "AccountNumber" => $_POST['AccountNumber'], "RoutingNumber" => $_POST['RoutingNumber'], "year" => $_POST['year'], "Amount" => $_POST['amount'], "Identifier" => $_POST['Identifier'], "messages" => $errMsgs);
		print_r(json_encode($resulterrors));
	} else {
		if (isset($result)) {
		if($result->passed == false || $result->passed == '') {
			$resultvalues = array("status" => "failed", "agent" => $agent, "month" => $_POST['month'], "AccountNumber" => $_POST['AccountNumber'], "RoutingNumber" => $_POST['RoutingNumber'], "year" => $_POST['year'], "passed" => $result->rawResult->VALIDATION_MESSAGE->RESULT, "MESSAGE" => $result->rawResult->VALIDATION_MESSAGE->VALIDATION_ERROR->MESSAGE, "Amount" => $result->rawResult->Amount, "identifier" => $result->identifier, "REQUEST_ID" => $result->rawResult['REQUEST_ID'], "resultCode" => $result->resultCode);
			print_r(json_encode($resultvalues));
		} else {
			$resultvalues = array("status" => "passed", "agent" => $agent, "month" => $_POST['month'], "AccountNumber" => $_POST['AccountNumber'], "RoutingNumber" => $_POST['RoutingNumber'], "year" => $_POST['year'], "passed" => $result->passed, "TRANSACTION_ID" => $result->rawResult->AUTHORIZATION_MESSAGE->TRANSACTION_ID, "Amount" => $result->rawResult->Amount, "identifier" => $result->identifier, "REQUEST_ID" => $result->rawResult['REQUEST_ID'], "resultCode" => $result->resultCode, "RESPONSE_TYPE" => $result->rawResult->AUTHORIZATION_MESSAGE->RESPONSE_TYPE, "RESPONSE_TYPE_TEXT" => $result->rawResult->AUTHORIZATION_MESSAGE->RESPONSE_TYPE_TEXT, "RESULT_CODE" => $result->rawResult->AUTHORIZATION_MESSAGE->RESULT_CODE, "TYPE_CODE" => $result->rawResult->AUTHORIZATION_MESSAGE->TYPE_CODE, "CODE" => $result->rawResult->AUTHORIZATION_MESSAGE->CODE, "MESSAGE" => $result->rawResult->AUTHORIZATION_MESSAGE->MESSAGE);
			print_r(json_encode($resultvalues));
		}
		}
	} 
?>