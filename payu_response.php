<?php

# Required File Includes
include("../../../init.php");
include("../../../includes/functions.php");
include("../../../includes/gatewayfunctions.php");
include("../../../includes/invoicefunctions.php");

$udf5="WHMCS_v_7.2";

$gatewaymodule = "payu"; # Enter your gateway module name here replacing template

$GATEWAY = getGatewayVariables($gatewaymodule);
if (!$GATEWAY["type"]) die("Module Not Activated"); # Checks gateway module is active before accepting callback

$response = array();
$response = $_POST;

# Get Returned Variables - Adjust for Post Variable Names from your Gateway's Documentation
$status = $response["status"];
$fee = $response[''];
$amount = $response["amount"];
$invoiceid = $response["txnid"];
$productInfo  = $response['productinfo'];
$firstname = $response['firstname'];
$email = $response['email'];
$udf5 = $response['udf5'];
$transid = $response["payuMoneyId"];
$respSignature = $response['hash'];



#$amount = ($request_params["transaction_amount"]) / 100;

$invoiceid = checkCbInvoiceID($invoiceid, 'payu'); # Checks invoice ID is a valid invoice number or ends processing

checkCbTransID($transid); # Checks transaction number isn't already in the database and ends processing if it does

#$invoiceid = checkCbInvoiceID($invoiceid,$GATEWAY["name"]); # Checks invoice ID is a valid invoice number or ends processing

#checkCbTransID($transid); # Checks transaction number isn't already in the database and ends processing if it does

$keyString	=  	$GATEWAY['MerchantKey'].'|'.$invoiceid.'|'.$amount.'|'.$productInfo.'|'.$firstname.'|'.$email.'|||||'.$udf5.'|||||';

$keyArray 	= 	explode("|",$keyString);
$reverseKeyArray 	= 	array_reverse($keyArray);
$reverseKeyString	=	implode("|",$reverseKeyArray);
$saltString     = $GATEWAY['SALT'].'|'.$status.'|'.$reverseKeyString;
$sentHashString = strtolower(hash('sha512', $saltString));

//if(empty($respSignature)) $sentHashString=$respSignature; // to bypass signature match
//$response['reverseHash'] = $sentHashString;
if($respSignature == $sentHashString)
{
	if($response['status']=='success') {
    	# Successful
    	# Check if amount is INR, convert if not.
    
        $querytouserid = select_query("tblinvoices", "userid", array("id" => $invoiceid)); #Get userid for the invoice using database
        $getpaymentuserid = mysql_fetch_assoc($querytouserid); 
        $currency = getCurrency(array_values($getpaymentuserid)[0]); #Get set currency for the current invoiced user

        if($currency['code'] !== 'INR') {
            $result = mysql_fetch_array(select_query( "tblcurrencies", "id", array( "code" => 'INR' )));
            $inr_id= $result['id'];
            $converted_amount = convertCurrency($amount, $inr_id, $currency['id']);
        }
        else {
            $converted_amount = $amount;
        }
        
        $fee = $converted_amount*0.02+($converted_amount*0.02)*0.01;
    
	    addInvoicePayment($invoiceid, $transid, $converted_amount, $fee, $gatewaymodule); # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
    	update_query("tblinvoices",array("notes"=>"PayUmoney"),array("id"=>$invoiceid));
		logTransaction($GATEWAY["name"],$response,"Successful"); # Save to Gateway Log: name, data array, status
	
	} else {
		# Unsuccessful
	    logTransaction($GATEWAY["name"],$response,"Unsuccessful"); # Save to Gateway Log: name, data array, status
 
	}
}
else
{
	logTransaction($GATEWAY["name"],$response,"Tampered"); # Save to Gateway Log: name, data array, status
}

$filename = $GATEWAY['systemurl'].'/viewinvoice.php?id=' . $invoiceid;     // path of your viewinvoice.php
HEADER("location:$filename");

?>
