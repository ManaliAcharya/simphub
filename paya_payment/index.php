<?php
// /*----------------------------------------------
// Author: SDK Support Group
// Company: Paya
// Contact: sdksupport@nuvei.com
// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
// !!! Samples intended for educational use only!!!
// !!!        Not intended for production       !!!
// !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
// -----------------------------------------------*/

/**
 * Test script for handling the response from the GETI check processing webservice
 * - See the switch() statement in the body for where the different result codes should be handled;
 * - Currently short messages are simply being printed; it is up to the client to implement what they want to do here
 * 
 * - The 'Prepopulate Form' select field is to test the form with valid values, to see the various responses.
 * 
 * required files: GETIECheckProcessor.php, SystemSettings.php, config.ini
 * - GETIECheckProcessor.php requires SystemSettings.php
 * - SystemSettings.php loads config.ini
 * 
 * @author 
 * 
 */
require_once('GETIECheckProcessor_PPD.php');

$isError = false;
// process the POST request
if (isset($_POST['action']) && $_POST['action'] == 'process') {
	$validForm = false;
	$errMsgs = array();
	
	$ChargeAmount = (strlen(trim($_POST['amount'])) ? $_POST['amount'] : 0);
// 	if ($ChargeAmount > 0) {
// 		$validForm = true;
// 	} else {
// 		$errMsgs[] = 'No amount to charge';
// 	}
	
	// the require parameters that aren't set in the test function
	$reqParams = array('RoutingNumber', 'Identifier');
	$params = array();
	foreach ($reqParams as $key) {
		if (!isset($_POST[$key]) || !strlen(trim($_POST[$key]))) {
			$validForm = false;
			$errMsgs[] = "Missing field: '$key'";
			break;
		}
		
		$params[$key] = $_POST[$key];
	}
	
	if ($validForm) {
		$result = ECheckProcessorTest::testProcess($params, $ChargeAmount);
	} else {
		$isError = true;
	}
}
?>

<html>
	<head>
	
		<style>
			label {
				display: inline-block;
				width: 150px;
			}
			
			#prepopulate {
				margin-bottom: 2em;
			}
		</style>

		<script type="text/javascript">
			/**
			 * The 'Prepopulate Form' select field
			 * This will prepopulate thte Amount, Routing Number, and Identifier fields with data that will result in a successful webservice response
			 * 
			 */
  document.write("Javascript");
			function loadFunc() {
				var prepopVals = [{
						label:			'authorization',
						RoutingNumber:	'490000018',
						Identifier:		'A'
					}, {
						label:			'check limit exceeded',
						RoutingNumber:	'490000018',
						Identifier:		'A',
						amount:			'-10.50'
					}, {
						label:			'decline',
						RoutingNumber:	'490000034',
						Identifier:		'A'
					}, {
						label:			'manager needed',
						RoutingNumber:	'490000021',
						Identifier:		'A'
					}, {
						label:			're-presented check',
						RoutingNumber:	'490000047',
						Identifier:		'A'
					}, {
						label:			'void',
						RoutingNumber:	'490000018',
						Identifier:		'V'
					}
				];

				// shorthand function for document.getElementById()
				var $ = function(id) {
					return document.getElementById(id);
				}

				// assign the DOM elements to vars
				var select = $('prepopulate');
				var routingNum = $('RoutingNumber');
				var identifier = $('Identifier');
				var amount = $('amount');

				// set the options in the select field
				select.options[0] = new Option('', '');
				for (var i = 0; i < prepopVals.length; i++) {
					select.options[i + 1] = new Option(prepopVals[i].label, i);
				}

				// the 'onchange' handler for the select field
				select.onchange = function(evt) {
					var index = select.options[select.selectedIndex].value;

					// set the fields according to which option is selected in the select field
					// the data comes from the objects defined above in 'prepopVals'
					if (index != '') {
						var valObj = prepopVals[index];
						routingNum.value = valObj.RoutingNumber;
						identifier.value = valObj.Identifier;
			
						if (valObj.amount) {
							amount.value = valObj.amount;
						} else {
							amount.value = '1.00';
						}

					// clear the fields if the first/empty option is selected
					} else {
						routingNum.value = '';
						identifier.value = '';
						amount.value = '';
					}
				};
			}
		</script>
	</head>
	
	<body onload="javascript: loadFunc();">

		<form name="" id="" action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post">
			<input type="hidden" name="action" value="process" />
			
			<div>
				<label for="prepopulate">Prepopulate Form: </label>
				<select id="prepopulate"></select>
			</div>
			
			<div>
				<label for="amount">Amount: </label>
				<input type="text" name="amount" id="amount" value="<?php echo (isset($_POST['amount']) ? $_POST['amount'] : ''); ?>" />
			</div>
			
			<div>
				<label for="RoutingNumber">Routing Number: </label>
				<input type="text" name="RoutingNumber" id="RoutingNumber" value="<?php echo (isset($_POST['RoutingNumber']) ? $_POST['RoutingNumber'] : ''); ?>" />
			</div>
			
			<div>
				<label for="Identifier">Identifier: </label>
				<input type="text" name="Identifier" id="Identifier" value="<?php echo (isset($_POST['Identifier']) ? $_POST['Identifier'] : ''); ?>" maxlength="1" />
			</div>
			
			<input type="reset" value="Reset" />
			<input type="submit" value="Submit" />
		</form>
		
		<?php
			// display our own error messages
			if ($isError) {
				?>
		<ul id="errorMessage">
				<?php
				foreach ($errMsgs as $errMsg) {
					?>
			<li><?php echo $errMsg; ?></li>
					<?php
				}
				?>
		</ul>
				<?php
			} else {
				?>
		<div>
			<?php
			
				// the webservice validation was successful - now handle the various result code scenarios
				if (isset($result) && $result->passed) {
					echo 'Validation Passed';
					
					echo '<div>';
					echo '<div>Result Code: '. $result->resultCode .'</div>';
					switch($result->resultCode) {
						case '0':
							// use the request identifier to differentiate between a check being voided and a regular approval, since the same result code is returned for both
							if ($result->identifier == 'V') {	// void
								echo 'check void approved -- perform appropriate action';
							} else {	// approved
								echo 'check approved -- perform appropriate action';
							}
							
							break;
						
						case '136':		// check limit exceeded
							echo 'check limited exceeded -- perform appropriate action';
							break;
							
						case '520':		// declined
							echo 'check declined -- perform appropriate action';
							break;
							
						case '132':		// warning - manager needed
							echo 'warning, manager needed -- perform appropriate action';
							break;
							
						case '4':		// warning - re-presented check
							echo 'warning, re-presented check -- perform appropriate action';
							break;
						
						default:
							echo 'invalid result code';
							break;
					}
					echo '</div>';
					
				} elseif (isset($_POST['action'])) {
					echo 'Validation failed';
				}
				
				
				// debugging only -- print out the webservice result object
				if (isset($result)) {
					// the webservice result object
					echo '<br /><br />raw result object:';
					echo '<pre>';
					print_r($result->rawResult);
					echo '</pre>';
				}
			?>
		</div>
				<?php
			}
		?>
	</body>
</html>