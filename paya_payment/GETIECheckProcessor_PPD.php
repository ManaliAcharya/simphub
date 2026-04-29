<?php error_reporting(E_ERROR | E_PARSE);

// /*----------------------------------------------
// Author: SDK Support Group
// Company: Paya
// Contact: sdksupport@nuvei.com
// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
// !!! Samples intended for educational use only!!!
// !!!        Not intended for production       !!!
// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
// -----------------------------------------------*/

/*
These classes make use of PHP's SOAP (http://php.net/manual/en/book.soap.php),
DOMDocument (http://php.net/manual/en/class.domdocument.php), and SimpleXML (http://php.net/manual/en/book.simplexml.php) extensions.  
We could probably eliminate SimpleXML and do a string search for 'EXCEPTION' in ECheckProcessor::IsCertified().
DOMDocument can be replaced with another method of generating the XML, such as string concatenation.

ECheckProcessorTest is an early snippet of what we used for testing that was eventually extended for Certification.

The $OPTIONS array in our test environment looks like
array('GETI'=>array(
'Server' =>'https://demo.eftchecks.com/webservices/authgateway.asmx?wsdl'
'NameSpace' =>'http://tempuri.org/GETI.eMagnus.WebServices/AuthGateway'
'UserName' =>'xxxxxxxxx'
'Password' =>'xxxxxxxxxxxx'
'TerminalID' ='xxxx'));

We generate it by parsing a configuration file for the application.
*/

require_once('SystemSettings_PPD.php');

class ECheckProcessor {
	public $debug;
	public $debugXML;

  
	function __construct($OPTIONS,$soapClient,$debug=false){
		$this->UserName = $OPTIONS['GETI']['UserName'];
		$this->Password = $OPTIONS['GETI']['Password'];
		$this->TerminalID = $OPTIONS['GETI']['TerminalID'];

		$this->soapClient = $soapClient;
		$this->debug = $debug;
		$this->debugXML = null;
		$this->debugResultXML = null;
	}
	
	

	function process($PaymentInfo,$amount,$User=null){
		if($this->debugXML){
			$XML = $this->debugXML;
		} else {
			$XML = $this->MakeDataPacket($PaymentInfo,$amount);
		}

		if($this->debug){
//			echo "DataPacket:\n";
//			echo $XML;
		}
		$rawResultXML = $this->ProcessCheck($XML,$this->debug);
		$resultObj = $this->ParsePaymentResult($rawResultXML);
		$resultObj->Amount = $amount;
//		$this->LogResult($resultObj,$User);
		return $resultObj;
	}

	public function ParsePaymentResult($rawResultXML){
		if($this->debugResultXML){
			$XML = $this->debugResultXML;
		} else {
			$XML = $rawResultXML;
		}

		/*
		Your code to parse the XML into $PaymentResult
		*/
		// parse the XML response into an object
		$PaymentResult = simplexml_load_string($XML);
		
		return $PaymentResult;
	}
	
	function LogResult($resultObj,$User=null){
		if ($User) {
			echo '<pre>';
			print_r($User);
			echo '</pre>';
		}
		
		echo '<pre>';
		print_r($resultObj);
		echo '</pre>';
	}

	private function MakeDataPacket($PaymentInfo,$amount){
		$Dom = new DOMDocument('1.0','ISO-8859-1');
		$Dom->formatOutput = true;

		$AuthElem = $Dom->createElement('AUTH_GATEWAY');

		$AuthElem->appendChild(new DOMAttr('xmlns:xsi','http://www.w3.org/2001/XMLSchema-instance'));
		$AuthElem->appendChild(new DOMAttr('xmlns:xsd','http://www.w3.org/2001/XMLSchema'));
		$AuthElem->appendChild(new DOMAttr('REQUEST_ID',$PaymentInfo->RequestID));

		$Transaction = $Dom->createElement('TRANSACTION');
		$TransactionID = $Dom->createElement('TRANSACTION_ID',$PaymentInfo->TransactionID);

		$Transaction->appendChild($TransactionID);

		$MerchantElem = $Dom->createElement('MERCHANT');
		$MerchantElem->appendChild($Dom->createElement('TERMINAL_ID',$this->TerminalID));
		$Transaction->appendChild($MerchantElem);

		$PacketElem = $Dom->createElement('PACKET');
		$Identifier = (string)'A';
		if(isset($PaymentInfo->Identifier)) {
			$Identifier = (string)$PaymentInfo->Identifier;
		}
		$IdentifierElem = $Dom->createElement('IDENTIFIER',$Identifier);
		$PacketElem->appendChild($IdentifierElem);

		$AccountElem = $Dom->createElement('ACCOUNT');
		$AccountElem->appendChild($Dom->createElement('ROUTING_NUMBER',$PaymentInfo->RoutingNumber));
		$AccountElem->appendChild($Dom->createElement('ACCOUNT_NUMBER',$PaymentInfo->AccountNumber));
		$AccountElem->appendChild($Dom->createElement('ACCOUNT_TYPE','Checking')); //Will possibly need to be dynamic
		$PacketElem->appendChild($AccountElem);

		$ConsumerElem = $Dom->createElement('CONSUMER');
		$ConsumerElem->appendChild($Dom->createElement('FIRST_NAME',$PaymentInfo->FirstName));
		$ConsumerElem->appendChild($Dom->createElement('LAST_NAME',$PaymentInfo->LastName));
		$ConsumerElem->appendChild($Dom->createElement('ADDRESS1',$PaymentInfo->Address1));
		$ConsumerElem->appendChild($Dom->createElement('ADDRESS2',$PaymentInfo->Address2));
		$ConsumerElem->appendChild($Dom->createElement('CITY',$PaymentInfo->City));
		$ConsumerElem->appendChild($Dom->createElement('STATE',$PaymentInfo->State));
		$ConsumerElem->appendChild($Dom->createElement('ZIP',$PaymentInfo->Zip));
		$ConsumerElem->appendChild($Dom->createElement('PHONE_NUMBER',$PaymentInfo->PhoneNumber));
		$ConsumerElem->appendChild($Dom->createElement('DL_STATE',$PaymentInfo->DLState));
		$ConsumerElem->appendChild($Dom->createElement('DL_NUMBER',$PaymentInfo->DLNumber));
		$ConsumerElem->appendChild($Dom->createElement('COURTESY_CARD_ID'));

		if((isset($PaymentInfo->SSN4) || isset($PaymentInfo->DOBYear)) && $PaymentInfo->MakeIdentityFlag){
			$IdentityElem  = $Dom->createElement('IDENTITY');
			if($PaymentInfo->SSN4){
				$IdentityElem->appendChild($Dom->createElement('SSN4',$PaymentInfo->SSN4));
			}
			$ConsumerElem->appendChild($IdentityElem);
		}

		$PacketElem->appendChild($ConsumerElem);

		$CheckElem = $Dom->createElement('CHECK');
		$CheckElem->appendChild($Dom->createElement('CHECK_AMOUNT',$amount));
		$PacketElem->appendChild($CheckElem);

		$Transaction->appendChild($PacketElem);
		$AuthElem->appendChild($Transaction);
		$String  = $Dom->saveXML($AuthElem);

		return $String;
	}

	public function IsCertified($XML){
		$sXML = new SimpleXMLElement($XML);
		if($sXML->EXCEPTION){
			return false;
		}
		return true;
	}

	private function ProcessCheck($XML,$debug=false){
		$params = array();
		$params['DataPacket'] = $XML;

		$terminalSettignsFNC = 'GetCertificationTerminalSettings';
		$processFNC = 'ProcessSingleCertificationCheck';
		if($debug){
			$terminalSettignsFNC = 'GetCertificationTerminalSettings';
			$processFNC = 'ProcessSingleCertificationCheck';
		}
		$result0 = $this->soapClient->$terminalSettignsFNC();

		if(!$this->IsCertified($result0->{$terminalSettignsFNC.'Result'})){
			return $result0->{$terminalSettignsFNC.'Result'};
		}
		$result1 = $this->soapClient->$processFNC($params);

		return $result1->{$processFNC.'Result'};
	}
}

class GETISoapClient extends SoapClient{
  public function __call($func, $args) {
        return $this->__soapCall($func, $args);
    }
    public function __soapCall($function, $arguments, $options = array(), $input_headers = null, &$output_headers = null){
        return parent::__soapCall($function, $arguments, $options, $input_headers, $output_headers);
    }

    function __doRequest($request, $location, $action, $version, $one_way = NULL) {
    	return parent::__doRequest($request,$location, $action, $version);
  	}
}

class GETISoapClientFactory {

	public function Create($OPTIONS){
	    $wsdl = trim(($OPTIONS['GETI']['Server']));   
		try{
		    $soapClient = new GETISoapClient($wsdl);
		} catch(\Exception $ex) {
		    echo $ex->getMessage(); die();
		}
		$headerparameters = array('UserName'=>$OPTIONS['GETI']['UserName'],'Password'=>$OPTIONS['GETI']['Password'],'TerminalID'=>$OPTIONS['GETI']['TerminalID']);

		$headers = new SoapHeader($OPTIONS['GETI']['NameSpace'], 'AuthGatewayHeader', $headerparameters);
		$soapClient->__setSoapHeaders(array($headers));
		return $soapClient;
	}
}
class ECheckProcessorFactory {
  
	/*
	SystemSettings parses a  configuration to get credentials for authenticating with GETI.
	*/
	public function Create($OPTIONS=null){
		if(!$OPTIONS){
			$OPTIONS = SystemSettings::get();
		}
		$newvari = new GETISoapClientFactory();		
		$soapClient = $newvari->Create($OPTIONS);
		$proc =  new ECheckProcessor($OPTIONS,$soapClient);
		return $proc;
	}
}

class ECheckProcessorTest {
  
	function testProcess($params, $ChargeAmount){
		// set default values for most of the parameters
		// in the live script, these values will have to be submitted as part of the form.  they only get set here for testing
		$defaultParams = array(
			'RequestID'			=> 'R'.date('ymdhis').rand(111,999),
			'TransactionID'		=> 'T'.date('ymdhis').rand(111,999),
			'RoutingNumber'		=> $params['RoutingNumber'],
			'AccountNumber'		=> $params['AccountNumber'],
			'CheckNumber'		=> $params['CheckNumber'],
			'FirstName'			=> $params['FirstName'],
			'LastName'			=> $params['LastName'],
			'Address1'			=> $params['Address1'],
			'Address2'			=> $params['Address2'],
			'City'				=> $params['City'],
			'State'				=> $params['State'],
			'Zip'				=> $params['Zip'],
			'PhoneNumber'		=> $params['PhoneNumber'],
			'DLState'			=> $params['DLState'],
			'DLNumber'			=> $params['DLNumber'],
			'Identifier'		=> $params['Identifier']
		);
		
		// merge the incoming parameters with the default parameters set above, and add them to the $PaymentInfo object
		$values = array_merge($defaultParams, $params);
		$PaymentInfo = new stdClass();
		foreach ($values as $key => $val) {
			$PaymentInfo->$key =  $val;
		}
		$newvar = new ECheckProcessorFactory();		
		$ECheckProcessor = $newvar->Create();
		$ECheckProcessor->debug = true;
		$resObj = $ECheckProcessor->process($PaymentInfo, $ChargeAmount);
		//echo '<pre>'; print_r($resObj); echo '</pre>';
		
		// check to see if the validation passed
		$passed = false;
		if (isset($resObj->VALIDATION_MESSAGE) && isset($resObj->VALIDATION_MESSAGE->RESULT) && $resObj->VALIDATION_MESSAGE->RESULT == 'Passed') {
			$passed = true;
		}
		
		// create the result object -- add the raw webservice result to it for debugging.
		$result = new stdClass();
		$result->rawResult = $resObj;
		$result->passed = $passed;
		$result->resultCode = $resObj->AUTHORIZATION_MESSAGE->RESULT_CODE;
		$result->identifier = $PaymentInfo->Identifier;
		return $result;
	}
}
