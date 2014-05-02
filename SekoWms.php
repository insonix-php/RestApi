<?php
/**
*  Component class for SekoWms implementing seko's method.
*/
class SekoWms
{
	private $_timeout = 90;
	private $_responseBit;
	private $_orderId;
	private $_guid;
	private $_path ;
	private $_defaultUrl = "https://uat1.etlogichub.com/hub/api/" ;
	private $_defaultToken = "888888888888888888888888888888888";
	private $_initialUrl;
	private $_token;
	
	public function __construct($config)
	{
		$this->_initialUrl = !empty($config['urlWMS'])?$config['urlWMS']:$this->_defaultUrl;
		$this->_token = !empty($config['tokenWMS'])?$config['tokenWMS']:$this->_defaultToken;
		
		$this->_path = YiiBase::getPathOfAlias('application.modules.logistics.wmslogs');
	}
	
     /*
	 * addToWms function is declared below. 
	 * The product array is passed through function to submit a web product.
	 * */
	public function addItem(array $data)
	{ 			
		//checking all the variables required for sending request
		if(empty($data['productMaster']))
		   throw new CHttpException(406,'ProductMaster Array is required array.');
		if(empty($data['productMaster']['productDescription']))
		   throw new CHttpException(406,'Product Description is required Field.');
		if(empty($data['productMaster']['productCode']))
		   throw new CHttpException(406,'Product Code is required Field.');
	
		if(empty($data['list']['supplierMapping']))
		   throw new CHttpException(406,'Supplier Mapping Array is required array.');
		   
		$supplierCode = array();  
		if(array_key_exists('0', $data['list']['supplierMapping'])) {			
			foreach ($data['list']['supplierMapping'] as $key => $productItem ) {
			
				if (in_array($productItem['supplierCode'], $supplierCode, true)) {
				throw new CHttpException(406,'supplierMapping ['.$key.']:Supplier code is required and Supplier Codes need to be unique  for product.');
				}
				$supplierCode[$key] = $productItem['supplierCode'];		
				
				if(empty($productItem['supplierDescription']))
				throw new CHttpException(406,'supplierMapping ['.$key.']:Supplier Description is required for product.');

				if(empty($productItem['uom']))
				throw new CHttpException(406,'supplierMapping ['.$key.']:UOM is required and need to be default value 1  for product.');
			}				
		} else {			
			if(empty($data['list']['supplierMapping']['supplierCode']))
			throw new CHttpException(406,'Supplier code required .');
			
			if(empty($data['list']['supplierMapping']['supplierDescription']))
			throw new CHttpException(406,'Supplier Description is required for product.');


			if(empty($data['list']['supplierMapping']['uom']))
			throw new CHttpException(406,'UOM is required field and need to be default value 1.');
		} 
	
		//setting up data in array to be sent as request 	
		$productArray =  ['ProductMaster' =>
							['HTSCode' => $data['productMaster']['codeHTS']?:'',
							'ProductDescription' => $data['productMaster']['productDescription']?:'',
							'ProductLongDescription' => $data['productMaster']['productLongDescription']?:'',
							'GUID' => $data['productMaster']['guid']?:'',
							'EAN' => $data['productMaster']['ean']?:'',
							'ProductCode' => $data['productMaster']['productCode'],
							],
						];	

		$arr = array();
		if(array_key_exists('0', $data['list']['supplierMapping'])){
			foreach($data['list']['supplierMapping'] as $key => $values){
				foreach($values as $key2 => $value){
					if($key2 == 'uom'){	
						$arr[$key][strtoupper($key2)] = $value;
					}else{
						$arr[$key][ucfirst($key2)] = $value;	
					}	
				}
			}
	    } else {
			foreach($data['list']['supplierMapping'] as $key => $value){
						if($key == 'uom'){	
							$arr[strtoupper($key)] = $value;
						}else{
							$arr[ucfirst($key)] = $value;	
						}			
			}
		}
		
		/*
		 * The product array is passed to function.
		 * The xml is created according to product array values in function.
		 */
		$xml = $this->array_to_xml($productArray, new SimpleXMLElement('<Request/>'))->asXML();
		
		/*
		 * The list array is passed to function with xml of $productArray.
		 * The new xml is generated and passes to function for reponse .
		 */
		$newXml = $this->addListProductXml('List', $arr , $xml );
			
		$dom = new DOMDocument;
		$dom->preserveWhiteSpace = FALSE;
		$dom->loadXML($newXml);
		$dom->encoding = 'UTF-8'; 
		$dom->formatOutput = TRUE;
		$xmlData = $dom->saveXml();       // saves the xml data in variable.
	
		/*
		 * $dom->save("D:/wamp/www/testyii/application/protected/modules/logistics/components/nameofXml.xml") to save the xml.
		 * The below curl which sends the data to seko hub for adding the product.
		 * The post method is used.
		 * The xml data is sent to the following url.
		 */
	
	
		$URL = "".$this->_initialUrl."products/v1/submit.xml?token=".$this->_token."";
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$URL);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT,$this->_timeout);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
		curl_setopt($ch, CURLOPT_SSLVERSION,3); 
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
		curl_setopt($ch, CURLOPT_POSTFIELDS,$xmlData);
		
		$request = $URL;
				
		if (curl_errno($ch)) {
			// moving to display page to display curl errors
			return  curl_error($ch);
		} else {
			/*
			 * Getting the response in this phase.
			 * creating an xml object to get in proper format.
			 */
			
			$response = curl_exec($ch);	
			if(empty($response))
				throw new CHttpException(400,'Response string is empty.');	
			
			$responseXml = new SimpleXMLElement($response , LIBXML_NOCDATA);
			$this->_responseBit = $responseXml->CallStatus->Success;
			$this->wmsItemLog($data, $request, $responseXml, 'addItem');			
			return $responseXml;
			curl_close($ch);
		}
	}
	
	/*
	 * addOrder function is declared below. 
	 * The order array is passed through function to submit a web order.
	 */
	public function addOrder(array $orderArray)
	{
		if(empty($orderArray['salesOrder']))
			return Logistics::getErrorResponse(804,'Sales Order is required field.');

		if(empty($orderArray['salesOrder']['salesOrderNumber']))
			return Logistics::getErrorResponse(804,'Sales Order Number is required.');
			
		if(empty($orderArray['salesOrder']['courierName']))
			return Logistics::getErrorResponse(804,'Sales Order Carrier name is required.');
			
		if(empty($orderArray['salesOrder']['courierService']))
			return Logistics::getErrorResponse(804,'Sales Order Carrier service is required.');
			
		if(empty($orderArray['salesOrder']['salesOrderDate']))
			return Logistics::getErrorResponse(804,'Sales Order date is required.');
			
		if(empty($orderArray['deliveryDetails']))
			return Logistics::getErrorResponse(804,'Delivery details is required field.');
			
		if(empty($orderArray['deliveryDetails']['firstName']))
			return Logistics::getErrorResponse(804,'Delivery details First name is required field.');
			
		if(empty($orderArray['deliveryDetails']['lastName']))
			return Logistics::getErrorResponse(804,'Delivery details Last name is required field.');
			
		if(empty($orderArray['deliveryDetails']['city']))
			return Logistics::getErrorResponse(804,'Delivery details City is required field.');
			
		if(empty($orderArray['deliveryDetails']['county']))
			return Logistics::getErrorResponse(804,'Delivery details county is required field.');
			
		if(empty($orderArray['deliveryDetails']['line1']))
			return Logistics::getErrorResponse(804,'Delivery details line1 is required field.');
			
		if(empty($orderArray['deliveryDetails']['postcodeZip']))
			return Logistics::getErrorResponse(804,'Delivery details postcode is required field.');
			
		if(empty($orderArray['deliveryDetails']['countryCode']))
			return Logistics::getErrorResponse(804,'Delivery details country is required field.');
			
		if(empty($orderArray['billingDetails']))
			return Logistics::getErrorResponse(804,'Billing details is required field.');
			
		if(empty($orderArray['billingDetails']['firstName']))
			return Logistics::getErrorResponse(804,'Billing details First name is required field.');
			
		if(empty($orderArray['billingDetails']['lastName']))
			return Logistics::getErrorResponse(804,'Billing details Last name is required field.');
			
		if(empty($orderArray['billingDetails']['city']))
			return Logistics::getErrorResponse(804,'Billing details City is required field.');
			
		if(empty($orderArray['billingDetails']['county']))
			return Logistics::getErrorResponse(804,'Billing details county is required field.');
			
		if(empty($orderArray['billingDetails']['line1']))
			return Logistics::getErrorResponse(804,'Billing details line1 is required field.');
			
		if(empty($orderArray['billingDetails']['postcodeZip']))
			return Logistics::getErrorResponse(804,'Billing details postcode is required field.');
			
		if(empty($orderArray['billingDetails']['countryCode']))
			return Logistics::getErrorResponse(804,'Billing details country is required field.');
		
		if(empty($orderArray['salesOrderHeader']))
			return Logistics::getErrorResponse(804,'Sales Order Header is required field.');
			
		if(empty($orderArray['salesOrderHeader']['dcCode']))
			return Logistics::getErrorResponse(804,'Order distribution center is required field.');
		
		if(empty($orderArray['list']['salesOrderLineItem']))
			return Logistics::getErrorResponse(803,'Order item is empty.');
		
		$lineNumber = array();
		$productCode = array();
		if(array_key_exists('0', $orderArray['list']['salesOrderLineItem'])) {
			foreach ($orderArray['list']['salesOrderLineItem'] as $key => $orderItem ) {
					 
				if(empty($orderItem['productCode']))
                    return Logistics::getErrorResponse(803,'SalesorderLineItem ['.$key.']:Order item product Code is required field.');
				
				if (in_array($orderItem['productCode'], $productCode, true)) {
					return Logistics::getErrorResponse(803,'SalesorderLineItem ['.$key.']:Order item product Code is required and needed to be unique for product.');
				}
				$productCode[$key] = $orderItem['productCode'];
				
				if(empty($orderItem['lineNumber']))
                    return Logistics::getErrorResponse(803,'SalesorderLineItem ['.$key.']:Order item line Number is required and needed to be unique for product code '.$orderItem['productCode'].'.');
				
				if (in_array($orderItem['lineNumber'], $lineNumber, true)) {
					return Logistics::getErrorResponse(803,'SalesorderLineItem ['.$key.']:Order item line Number is required and needed to be unique for product.');
				}
				$lineNumber[$key] = $orderItem['lineNumber'];

				if(empty($orderItem['currencyCode']))
                    return Logistics::getErrorResponse(803,'SalesorderLineItem ['.$key.']:Order item currency code is required for product code '.$orderItem['productCode'].'.');				
				
				if(empty($orderItem['quantity']))
                    return Logistics::getErrorResponse(803,'SalesorderLineItem ['.$key.']:Order item quantity is required field '.$orderItem['productCode'].'.');


				if(empty($orderItem['unitPrice']))
                    return Logistics::getErrorResponse(803,'SalesorderLineItem ['.$key.']:Order item unitPrice is required field '.$orderItem['productCode'].'.');					
			}				
		} else {			
			if(empty($orderArray['list']['salesOrderLineItem']['currencyCode']))
                return Logistics::getErrorResponse(803,'Order item currency code is required field.');


			if(empty($orderArray['list']['salesOrderLineItem']['productCode']))
                return Logistics::getErrorResponse(803,'Order item product Code is required field.');


			if(empty($orderArray['list']['salesOrderLineItem']['quantity']))
                return Logistics::getErrorResponse(803,'Order item quantity is required field.');


			if(empty($orderArray['list']['salesOrderLineItem']['unitPrice']))
                return Logistics::getErrorResponse(803,'Order item unitPrice is required field.');		
		} 
	
		$dcCode	= Logistics::getDcCode($orderArray['salesOrderHeader']['warehouseID']);
        if(is_array($dcCode) && array_key_exists('success', $dcCode))
                return $dcCode;
		
		$formatOrderArray =  ['WebSalesOrder' =>
						['Notes' => $orderArray['salesOrder']['notes']?:'',
						'SpecialInstructions' => $orderArray['salesOrder']['specialInstructions']?:'',
						'SalesOrderStatus' => $orderArray['salesOrder']['salesOrderStatus']?:'',
						'ShipmentTerms' => $orderArray['salesOrder']['shipmentTerms']?:'',
						'SalesOrderNumber' => $orderArray['salesOrder']['salesOrderNumber'],
						'SalesOrderReference' => $orderArray['salesOrder']['salesOrderReference']?:'salesOrderReference',
						'UltimateDestination' => $orderArray['salesOrder']['ultimateDestination']?:'',
						'CourierName' => $orderArray['salesOrder']['courierName']?:'',
						'NotificationMethod' => $orderArray['salesOrder']['notificationMethod']?:'',
						'SalesOrderDate' => $orderArray['salesOrder']['salesOrderDate']?:''	],
			
					   ['DeliveryDetails' =>									
								['ContactCode' => $orderArray['deliveryDetails']['contactCode']?:'',
								'Title' => $orderArray['deliveryDetails']['title']?:'',
								'FirstName' => $orderArray['deliveryDetails']['firstName'],
								'LastName' => $orderArray['deliveryDetails']['lastName'],
								'City' => $orderArray['deliveryDetails']['city'],
								'County' => $orderArray['deliveryDetails']['county'],
								'Line1' => $orderArray['deliveryDetails']['line1'],
								'Line2' => $orderArray['deliveryDetails']['line2']?:'',
								'Line3' => $orderArray['deliveryDetails']['line3']?:'',
								'Line4' => $orderArray['deliveryDetails']['line4']?:'',
								'PostcodeZip' => $orderArray['deliveryDetails']['postcodeZip'],
								'CountryCode' => $orderArray['deliveryDetails']['countryCode'],
								'PhoneNumber' => $orderArray['deliveryDetails']['phoneNumber']?:'',
								'EmailAddress' => $orderArray['deliveryDetails']['emailAddress']?:'']		
						],
					   ['BillingDetails' =>									
								['ContactCode' => $orderArray['billingDetails']['contactCode']?:'',
								'Title' => $orderArray['billingDetails']['title']?:'',
								'FirstName' => $orderArray['billingDetails']['firstName'],
								'LastName' => $orderArray['billingDetails']['lastName'],
								'City' => $orderArray['billingDetails']['city'],
								'County' => $orderArray['billingDetails']['county'],
								'Line1' => $orderArray['billingDetails']['line1'],
								'Line2' => $orderArray['billingDetails']['line2']?:'',
								'Line3' => $orderArray['billingDetails']['line3']?:'',
								'Line4' => $orderArray['billingDetails']['line4']?:'',
								'PostcodeZip' => $orderArray['billingDetails']['postcodeZip'],
								'CountryCode' => $orderArray['billingDetails']['countryCode'],
								'PhoneNumber' => $orderArray['billingDetails']['phoneNumber']?:'',
								'EmailAddress' => $orderArray['billingDetails']['emailAddress']?:'']		
						],
						['ForwardingAgent'=>									
								[
								'CompanyCode' => $orderArray['forwardingAgent']['contactCode']?:'',
								'CompanyDescription' => $orderArray['forwardingAgent']['contactCode']?:'',
								'ContactCode' => $orderArray['forwardingAgent']['contactCode']?:'',
								'Title' => $orderArray['forwardingAgent']['title']?:'',
								'FirstName' => $orderArray['forwardingAgent']['firstName']?:'',
								'LastName' => $orderArray['forwardingAgent']['lastName']?:'',
								'City' => $orderArray['forwardingAgent']['city']?:'',
								'County' => $orderArray['forwardingAgent']['county']?:'',
								'Line1' => $orderArray['forwardingAgent']['line1']?:'',
								'Line2' => $orderArray['forwardingAgent']['line2']?:'',
								'Line3' => $orderArray['forwardingAgent']['line3']?:'',
								'Line4' => $orderArray['forwardingAgent']['line4']?:'',
								'PostcodeZip' => $orderArray['forwardingAgent']['postcodeZip']?:'',
								'CountryCode' => $orderArray['forwardingAgent']['countryCode']?:'',
								'PhoneNumber' => $orderArray['forwardingAgent']['phoneNumber']?:'',
								'EmailAddress' => $orderArray['forwardingAgent']['emailAddress']?:'']							
						],
						['SalesOrderHeader'=>									
								['DCCode' => $dcCode ]						
						],
					]
				;
						    	    
	    $arr = array();
	    if(array_key_exists('0', $orderArray['list']['salesOrderLineItem'])){
			foreach($orderArray['list']['salesOrderLineItem'] as $key => $values){
				foreach($values as $key2 => $value){
					if($key2 == 'guid' || $key2 == 'vat' || $key2 == 'ean'){	
						$arr[$key][strtoupper($key2)] = $value;
					}else{
						$arr[$key][ucfirst($key2)] = $value;	
					}	
				}
			}
	    } else {
			foreach($orderArray['list']['salesOrderLineItem'] as $key => $value){
				if($key == 'guid' || $key == 'vat' || $key == 'ean'){	
					$arr[strtoupper($key)] = $value;
				}else{
					$arr[ucfirst($key)] = $value;	
				}			
			}
		}
		
		/*
		 * The order array is passed to function.
		 * The xml is created according to order array values in function.
		 */
		$xml = $this->array_to_xml($formatOrderArray, new SimpleXMLElement('<Request/>'))->asXML();
		
		/*
		 * The list array is passed to function with xml of $formatOrderArray.
		 * The new xml is generated and passes to function for reponse .
		 */					
		$newXml = $this->addListOrderXml('List', $arr , $xml );
					
		$dom = new DOMDocument;
		$dom->preserveWhiteSpace = FALSE;
		$dom->loadXML($newXml);
		$dom->encoding = 'UTF-8'; 
		$dom->formatOutput = TRUE;
		$xmlData = $dom->saveXml(); 
		    			
		
		/*
		 * $dom->save("nameofXml.xml") to save the xml.
		 * The below curl which sends the data to seko hub to Submit web order .
		 * The post method is used.
		 * The xml data is sent to the following url to Submit web order.
		 */
		$URL = "".$this->_initialUrl."salesorders/v1/websubmit.xml?token=".$this->_token."";
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$URL);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT,$this->_timeout);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
		curl_setopt($ch, CURLOPT_SSLVERSION,3); 
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
		curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlData);
		$request = $URL;
		
		if (curl_errno($ch)) {
			// moving to display page to display curl errors
			
			return  curl_error($ch);
		} else {
			/*
			 * Getting the response in this phase.
			 * creating an xml object to get in response in proper format.
			 *
			 */
			$response = curl_exec($ch);
			if(empty($response)){

				// intializing the queue instance
				$orderQueue = new OrderQueue();

				// checking if this order is already added
				$criteria = new CDbCriteria();
				$criteria->condition = "orderNumber = :oNo";	
				$criteria->params = [':oNo' => $orderArray['salesOrder']['salesOrderNumber']];
				$criteria->select = "id, counter";
				$wor = WMSQueues::model()->find($criteria);
				
				//Updating the counter in the queue
				if($wor){
					$attributes = ['counter' => $wor->counter+1];
					$condition = 'orderNumber = :oNo';
					$params = [':oNo' => $orderArray['salesOrder']['salesOrderNumber']];
					$update_queue = WMSQueues::model()->updateAll($attributes, $condition, $params);
					$this->wmsOrderLog($orderArray, 'connection failed', 'connection failed', 'addOrder');
					return Logistics::getErrorResponse(802,'Seko WMS service Unavailable. Order added to Queue.');
				
				} else {
					// adding a new entry to the queue
					$orderQueue->enqueue($orderArray, 'order', 'connection failed');
					$this->wmsOrderLog($orderArray, 'connection failed', 'connection failed', 'addOrder');
					return Logistics::getErrorResponse(802,'Seko WMS service Unavailable. Order added to Queue.');
				}
				
			} else {				
				$responseXml = new SimpleXMLElement($response);
				$this->_responseBit = $responseXml->CallStatus->Success;
				$this->_guid      = $responseXml->GUID;
				$this->_orderId   = $orderArray['salesOrder']['salesOrderNumber'];
				$this->wmsOrderLog($orderArray, $request, $responseXml, 'addOrder');			
				return $responseXml;
				curl_close($ch);
			}
		}
	}
	
	/*
	* Below is the function for creating xml format of the Array.
	* We are submitting Data as XML.
	* The function return us data as xml.
	*/
	private function array_to_xml(array $arr, SimpleXMLElement $xml) 
	{
		foreach ($arr as $k => $v) {
			$attrArr = array();
			$kArray = explode(' ',$k);
			$tag = array_shift($kArray);

			if (count($kArray) > 0) {
				foreach($kArray as $attrValue) {
					$attrArr[] = explode('=',$attrValue);                   
				}
			}

			if (is_array($v)) {
				if (is_numeric($k)) {
					$this->array_to_xml($v, $xml);
				} else {
					$child = $xml->addChild($tag);
					if (isset($attrArr)) {
						foreach($attrArr as $attrArrV) {
							$child->addAttribute($attrArrV[0],$attrArrV[1]);
						}
					}                   
					$this->array_to_xml($v, $child);
				}
			} else {
				$child = $xml->addChild($tag, $v);
				if (isset($attrArr)) {
					foreach($attrArr as $attrArrV) {
						$child->addAttribute($attrArrV[0],$attrArrV[1]);
					}
				}
			}               
		}

		return $xml;
	}
	
	/*
	* Below is the function for creating xml format of the multidimentional Array for 	  
	  products ordered.
	* We are appending  list Data as XML to existing xml.
	* The function return us data as xml for SalesOrderItem.
	*/	
	private function addListOrderXml($tag, $hash , $xml) 
	{
		$dom = new DOMDocument('1.0');
		$dom->encoding = 'UTF-8'; 
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		$dom->loadXML($xml);

		$parent = $dom->createElement($tag);
		$dom->documentElement->appendChild($parent);
		if(array_key_exists('0', $hash)){
			foreach($hash as $elm => $value){
				if (is_array($value)) {			
				 $pr = $dom->createElement('SalesOrderLineItem'); 
				  $parent->appendChild($pr);		
					foreach ($value as $elmt => $values ) {									 			
						$n = $dom->createElement($elmt);
						$n->appendChild( $dom->createTextNode( $values ) );
						$pr->appendChild($n);			
					}
			   }
			}
		} else {
			$pr = $dom->createElement('SalesOrderLineItem'); 
			$parent->appendChild($pr);
			foreach($hash as $elm => $value){
				$n = $dom->createElement($elm);
				$n->appendChild( $dom->createTextNode( $value ) );
				$pr->appendChild($n);
			}
		}
		
		$xmlData = $dom->saveXml();   	
		return   $xmlData ;  			
	}
	
	/*
	* Below is the function for creating xml format of the multidimentional Array for supplier list.
	* We are appending  list Data as XML to existing xml.
	* The function return us data as xml for ShipToCompanyMapping.
	*/	
	private function addListProductXml($tag, $hash , $xml) 
	{
		$dom = new DOMDocument('1.0');
		$dom->encoding = 'UTF-8'; 
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		$dom->loadXML($xml);

		$parent = $dom->createElement($tag);
		$dom->documentElement->appendChild($parent);
		if(array_key_exists('0', $hash)) {
			foreach($hash as $elm => $value) {
				if (is_array($value)) {			
				 $pr = $dom->createElement('ShipToCompanyMapping'); 
				  $parent->appendChild($pr);		
					foreach ($value as $elmt => $values ) {									 			
						$n = $dom->createElement($elmt);
						$n->appendChild( $dom->createTextNode( $values ) );
						$pr->appendChild($n);			
					}
			   }
			}
		} else {
			$pr = $dom->createElement('ShipToCompanyMapping'); 
			$parent->appendChild($pr);
			foreach($hash as $elm => $value) {
				$n = $dom->createElement($elm);
				$n->appendChild( $dom->createTextNode( $value ) );
				$pr->appendChild($n);
			}
		}
		
		$xmlData = $dom->saveXml();   	
		return   $xmlData ;  			
	}
		
	 /*
	 * getOnHand function is declared below. 
	 * The distribution center code  and product code are passed through function to get a product inventory.
	 * */
	public function getOnHand($dccode = null, $productcode = null)
	{ 	
		$URL = "".$this->_initialUrl."stock/v1/dc/" . $dccode . "/product/" .$productcode . ".xml?token=" . $this->_token . "";
				
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$URL);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT,$this->_timeout);
		//curl_setopt($ch, CURLOPT_GET, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSLVERSION,3); 
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));	

		if (curl_errno($ch)) {
			// moving to display page to display curl errors
			return  curl_error($ch);
		} else {
			/*
			 * Getting the response in this phase.
			 * creating an xml object to get in proper format.
			 */
			$response = curl_exec($ch);		
			if(empty($response))
				throw new CHttpException(400,'Seko Response string is empty.');	
			
			$responseXml = new SimpleXMLElement($response);
			
			return $responseXml;
			curl_close($ch);
		}
	}
	
	/*
	 * getOnHand function is declared below. 
	 * The distribution center code  and product code are passed through function to get a 
		product inventory.
	 */
	public function getAllOnHand($warehouseID)
	{ 
		$dcCode= Logistics::getDcCode($warehouseID);
		$data    = array('dccode' => $dcCode);		
		
		/*if(empty($dcCode))
			throw new CHttpException('Dccode is empty. Please assign dccode to warehouse.');*/
	
		$URL = "".$this->_initialUrl."stock/v1/dc/".$dcCode.".xml?token=".$this->_token."";
		$request = $URL;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$URL);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT,$this->_timeout);
		//curl_setopt($ch, CURLOPT_GET, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSLVERSION,3);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));	

		if (curl_errno($ch)) {
			// moving to display page to display curl errors
			return  curl_error($ch);
		} else {
			/*
			 * Getting the response in this phase.
			 * creating an xml object to get in proper format.
			 */
			$response = curl_exec($ch);
			$responseXml = new SimpleXMLElement($response);	
			$newDataSet = (array)$responseXml->List;
			$this->_responseBit = $responseXml->CallStatus->Success;
			$this->wmsInventoryLog($data, $request, $responseXml, 'getAllOnHand');
			$tmp = array();
		   
			if(is_array($newDataSet['StockQuantityLineItem'])) {
				foreach ($newDataSet['StockQuantityLineItem'] as $key =>  $item) {
					$tmp = (array)$item; 
					if(!empty($tmp)) {
						//Checking if already present sku and warehouse
						$attributes = ['quantity' => (int)$tmp['FreeQuantity']];
						$condition = 'warehouseID = :wid AND SKU= :sku';
						$params = [':wid' => (int)$warehouseID, ':sku' => $tmp['ProductCode']];
						$update_item = LProductInventory::model()->updateAll($attributes, $condition, $params); 
					}
				}
			} else { 
				$tmp = (array)$newDataSet['StockQuantityLineItem'];   
				//Checking if already present sku and warehouse
				$attributes = ['quantity' => (int)$tmp['FreeQuantity']];
				$condition = 'warehouseID = :wid AND SKU= :sku';
				$params = [':wid' => (int)$warehouseID, ':sku' => $tmp['ProductCode']];
				$update_item = LProductInventory::model()->updateAll($attributes, $condition, $params);    
			}
			curl_close($ch);
		}
	}
	
	/*
	 * getTrackingNumber function is declared below. 
	 * The GUID code of the order are passed through function to get the tracking number.
	 */
	public function getTrackingNumber($GUID = null, $orderID, $clientID)
	{ 	
		$data = array('GUID'=> $GUID);
		
		$URL = "".$this->_initialUrl."salesorders/v1/".$GUID."/tracking.xml?token=".$this->_token."";
		$request = $URL;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$URL);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT,$this->_timeout);
		//curl_setopt($ch, CURLOPT_GET, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSLVERSION,3);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));	

		if (curl_errno($ch)) 
		{
			// moving to display page to display curl errors
			return  curl_error($ch);
		} 
		else 
		{
			/*
			 * Getting the response in this phase.
			 * creating an xml object to get in proper format.
			 */
			$response = curl_exec($ch);			
			$responseXml = new SimpleXMLElement($response);
			$this->_responseBit = $responseXml->CallStatus->Success;
			$this->wmsTrackingLog($data, $request, $responseXml, 'getTrackingNumber');
			
			$criteria = new CDbCriteria();
			$criteria->condition = 'orderID = :orderID AND trackingNumber = :trackno';
			$criteria->params = array(':orderID'=> $orderID, ':trackno' => $responseXml->TrackingNumber);
			$items = LTrackingNumber::model()->findAll($criteria);
			if(!$items)
			{
				$siko_item = new LTrackingNumber();
				$siko_item->orderID        = $orderID;
				$siko_item->trackingNumber = $responseXml->TrackingNumber;
				$siko_item->shipDate	   = date('Y-m-d');
				$siko_item->clientID	   = $clientID;	
				$siko_item->save();
			}
			
			return $responseXml;
			curl_close($ch);
		}
	}

	/*
	 * getOrderTracking function is declared below. 
	 * The warehouseID is passed to get the GUID of the order.
	 */
	public function getOrderTracking($warehouseID)
	{
		//echo date("Y-m-d H:m:s",strtotime("-6 hours"));die('test');
		$previousTime = date("Y-m-d H:m:s",strtotime("-6 hours"));
		$criteria = new CDbCriteria();
		$criteria->condition = 'warehouseID = :wID AND created >= :pTime';
		$criteria->params = array(':wID' => $warehouseID, ':pTime' => $previousTime);
		$orders = LOrders::model()->findAll($criteria);
		echo "<PRE>";
		print_r($orders);
		
		$data_structure = array();
		$i= 0;
		if($orders){
			
			foreach ((array)$orders as $order) {
					//print_r($order);
				 $data_structure[$i]['GUID'] = $GUID     = $order->GUID;
				 $data_structure[$i]['orderID'] = $orderID  = $order->orderID;
				 $data_structure[$i]['clientID'] = $clientID = $order->clientID;
				 $i++;
				print_r($data_structure);
				


				//$this->getTrackingNumber($GUID, $orderID, $clientID);
			}
			//print_r($data_structure);
		}
	}
	
	/*
	 * cancelOrder function is declared below. 
	 * The GUID code of the order is passed to make the order cancel.
	 */
	function cancelOrder( $guid ) 
	{
		$url= "".$this->_initialUrl."salesorders/v1/".$guid."/cancel/reasoncode/006.xml?token=".$this->_token."";
    
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT,$this->_timeout);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
		curl_setopt($ch, CURLOPT_SSLVERSION,3);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
		if (curl_errno($ch)) {
			// moving to display page to display curl errors
			return  curl_error($ch);
		} else {
			/*
			 * Getting the response in this phase.
			 * creating an xml object to get in response in proper format.
			 * */
			$response = curl_exec($ch);      
				
			return $response;
			curl_close($ch);
		}
	}
	
	/*
	 * cancelPickingOrder function is declared below. 
	 * The orderID is passed to get the GUID of the order.
	 */
	public function cancelPickingOrder($orderID)
	{
		// Get list of orders
		$criteria = new CDbCriteria();
		$criteria->condition = 'orderID = :oID';
		$criteria->params = array(':oID'=> $orderID);
		$orders = LOrders::model()->find($criteria);

		if($orders){
			$GUID = $orders->GUID;
			return $this->cancelOrder($GUID);
		}
	}
		
	/*
	* Below is the function to save wms add item log.
	* Save the response and request.
	*/
	private function wmsItemLog(array $data, $request = null, $response = null, $action = null)
	{
		//log to the database
		$wmslog = new WMSLogs();
		$wmslog->date = date("Y-m-d H:i:s");
		$wmslog->request = $request?:"";
		$wmslog->response = json_encode($response)?:"";
		$wmslog->data = json_encode($data);
		$wmslog->orderID = null;
		$wmslog->action = $action;
		$wmslog->clientID = Yii::app()->user->getClient();
		$wmslog->statusID = $this->_responseBit =='true'?1:0;
		$wmslog->save();
		
		//logging information to the log file

		$date = date('d-m-Y');
		$timestamp = date('d-m-y H:m:s)');
		$file = $this->_path.'/logs_'.$date.'.txt';
		$content = "Date:{$timestamp}\r\n\r\nAction:{$wmslog->action} \r\n\r\nRequest: {$wmslog->request}\r\n\r\nResponse:{$wmslog->response}\r\n\r\nStatus:{$wmslog->statusID}\r\n\r\nData:{$wmslog->data} \r\n\r\n********************************************************************\r\n";
		  
		if(file_exists($file)) {  
			file_put_contents($file, $content, FILE_APPEND); 
		} else {
			file_put_contents($file, $content); 
		}	
	}

	/*
	* Below is the function to save wms place order log.
	* Save the response and request.
	*/
	private function wmsOrderLog(array $data, $request = null, $response = null, $action = null)
	{
		//log to the database
		$wmslog = new WMSLogs();
		$wmslog->date = date("Y-m-d H:i:s");
		$wmslog->request = $request?:"";
		$wmslog->response = json_encode($response)?:"";
		$wmslog->data = json_encode($data);
		$wmslog->orderID = $this->_orderId;
		$wmslog->GUID = $this->_guid;
		$wmslog->action = $action;
		$wmslog->clientID = Yii::app()->user->getClient();
		$wmslog->statusID = $this->_responseBit =='true'?1:0;
		$wmslog->save();

		//logging information to the log file
		$date = date('d-m-Y');
		$timestamp = date('d-m-y H:m:s)');
		$file = $this->_path.'/logs_'.$date.'.txt';
		$content = "Date:{$timestamp}\r\n\r\nAction:{$wmslog->action} \r\n\r\nRequest: {$wmslog->request}\r\n\r\nResponse:{$wmslog->response}\r\n\r\nStatus:{$wmslog->statusID}\r\n\r\nData:{$wmslog->data} \r\n\r\n********************************************************************\r\n";
		  
		if(file_exists($file)) {  
		   file_put_contents($file, $content, FILE_APPEND); 
		} else {
		   file_put_contents($file, $content); 
		}	

	}

	/*
	* Below is the function to save wms add inventory log.
	* Save the response and request.
	*/
	private function wmsInventoryLog(array $data, $request = null, $response = null, $action = null)
	{
		//log to the database
		$wmslog = new WMSLogs();
		$wmslog->date = date('d-m-y H:m:s)');
		$wmslog->request = $request?:"";
		$wmslog->response = json_encode($response)?:"";
		$wmslog->data = json_encode($data);
		$wmslog->orderID = null;
		$wmslog->action = $action;
		$wmslog->clientID = null;
		$wmslog->statusID = $this->_responseBit =='true'?1:0;
		$wmslog->save();

		//logging information to the log file
		$date = date('d-m-Y');
		$timestamp = date('d-m-y H:m:s)');
		$file = $this->_path.'/logs_'.$date.'.txt';
		$content = "Date:{$timestamp}\r\n\r\nAction:{$wmslog->action} \r\n\r\nRequest: {$wmslog->request}\r\n\r\nResponse:{$wmslog->response}\r\n\r\nStatus:{$wmslog->statusID}\r\n\r\nData:{$wmslog->data} \r\n\r\n********************************************************************\r\n";
		  
		if(file_exists($file)) {  
		   file_put_contents($file, $content, FILE_APPEND); 
		} else {
		   file_put_contents($file, $content); 
		}	
	}


	/*
	* Below is the function to save wms add inventory log.
	* Save the response and request.
	*/
	private function wmsTrackingLog(array $data, $request = null, $response = null, $action = null)
	{
		//log to the database
		$wmslog = new WMSLogs();
		$wmslog->date = date('d-m-y H:m:s)');
		$wmslog->request = $request?:"";
		$wmslog->response = json_encode($response)?:"";
		$wmslog->data = json_encode($data);
		$wmslog->orderID = null;
		$wmslog->action = $action;
		$wmslog->clientID = null;
		$wmslog->statusID = $this->_responseBit =='true'?1:0;
		$wmslog->save();

		//logging information to the log file
		$date = date('d-m-Y');
		$timestamp = date('d-m-y H:m:s)');
		$file = $this->_path.'/logs_'.$date.'.txt';
		$content = "Date:{$timestamp}\r\n\r\nAction:{$wmslog->action} \r\n\r\nRequest: {$wmslog->request}\r\n\r\nResponse:{$wmslog->response}\r\n\r\nStatus:{$wmslog->statusID}\r\n\r\nData:{$wmslog->data} \r\n\r\n********************************************************************\r\n";
		  
		if(file_exists($file)) {  
		   file_put_contents($file, $content, FILE_APPEND); 
		} else {
		   file_put_contents($file, $content); 
		}	
	}
}
