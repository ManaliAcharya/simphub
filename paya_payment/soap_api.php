 <?php 
 callme();
 //Data, connection, auth
 function callme() {
 $dataFromTheForm = '1.01'; // request data from the form
 $soapUrl = "https://getigateway.eftchecks.com/webservices/authgateway.asmx"; // asmx URL of WSDL
 $soapUser = "ImpactPaysGateway";  //  username
 $soapPassword = 'a8TqnNEN$9CVnSL5'; // password

 // xml post structure

 $xml_post_string = '<?xml version="1.0" encoding="utf-8"?>
<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
  <soap12:Header>
    <AuthGatewayHeader xmlns="https://getigateway.eftchecks.com/webservices/authgateway.asmx" />
  </soap12:Header>
  <soap12:Body>
    <GetCertificationToken xmlns="https://getigateway.eftchecks.com/webservices/authgateway.asmx">
      <DataPacket>string</DataPacket>
    </GetCertificationToken>
  </soap12:Body>
</soap12:Envelope>';   // data from the form, e.g. some ID number

    $headers = array(
                 "Content-type: text/xml;charset=\"utf-8\"",
                 "Accept: text/xml",
                 "Cache-Control: no-cache",
                 "Pragma: no-cache",
                 "SOAPAction: http://connecting.website.com/WSDL_Service/GetPrice", 
                 "Content-length: ".strlen($xml_post_string),
             ); //SOAPAction: your op URL

     $url = $soapUrl;

     // PHP cURL  for https connection with auth
     $ch = curl_init();
     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
     curl_setopt($ch, CURLOPT_URL, $url);
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
     curl_setopt($ch, CURLOPT_USERPWD, $soapUser.":".$soapPassword); // username and password - declared at the top of the doc
     curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
     curl_setopt($ch, CURLOPT_TIMEOUT, 10);
     curl_setopt($ch, CURLOPT_POST, true);
     curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string); // the SOAP request
     curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

     // converting
     $response = curl_exec($ch); 
     curl_close($ch);

     // converting
     $response1 = str_replace("<soap:Body>","",$response);
     $response2 = str_replace("</soap:Body>","",$response1);

     // convertingc to XML
     $parser = simplexml_load_string($response2);
     // user $parser to get your data out of XML response and to display it. 
     echo $parser;
 }
 ?>