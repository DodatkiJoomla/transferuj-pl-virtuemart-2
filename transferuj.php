<?php
/**
 *  @copyright Copyright (c) 2014 DodatkiJoomla.pl
 *  @license GNU/GPL v2
 */
if (!defined('_VALID_MOS') && !defined('_JEXEC')) die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');


// jeżeli klasa vmPSPlugin nie istnieje, dołącz
if (!class_exists('vmPSPlugin'))
{
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}
	
class plgVmPaymentTransferuj extends vmPSPlugin
{

    public static $_this = false;

	// konstruktor
    function __construct(& $subject, $config) 
	{
		// konstruktor kl. nadrzędnej
		parent::__construct($subject, $config);
		
		$this->_loggable = true;
		
	
		// to poniżej apisuje wartości z xml'a do kol. payment_params tabeli #__virtuemart_paymentmethods	
		$this->tableFields = array_keys($this->getTableSQLFields());
		$varsToPush = array(
			'transferuj_id' => array('', 'string'),
			'transferuj_kod_potwierdzajacy' => array('', 'string'),
			'transferuj_online'  => array(0, 'int'),
            'transferuj_formularz'  => array(1, 'int'),
			
            'transferuj_wyn_url' => array('', 'string'),

            'status_pending' => array('', 'string'),
            'status_success' => array('', 'string'),
            'status_canceled' => array('', 'string'),

            // DJ 2014-07-09 Zmieniam nazwę
            'cost_per_transaction' => array(0, 'double'),
            'cost_percent_total' => array(0, 'double'),

            'tax_id' => array(0, 'int'),
            'autoredirect' => array(1, 'int'),
            'powiadomienia' => array(1, 'int'),
			'payment_logos' => array('', 'string'),
			'payment_image' => array('', 'string'),
			'checkout_text' => array('', 'string')
	    );
		
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
		
	}
	
	function getTableSQLFields() 
	{
		$SQLfields = array(
			'id' => ' int(11) UNSIGNED NOT NULL AUTO_INCREMENT ',
			'virtuemart_order_id' => ' int(11) UNSIGNED DEFAULT NULL',
			'order_number' => ' char(32) DEFAULT NULL',
			'virtuemart_paymentmethod_id' => ' mediumint(1) UNSIGNED DEFAULT NULL',
			'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
			'tax_id' => 'int(11) DEFAULT NULL',
			'transferuj_crc' => 'varchar(32) ',
            'kwota_zamowienia' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' '			
		);
		return $SQLfields;
    }
	
	// potwierdzenie zamówienia funkcja 
	
	function plgVmPotwierdzenieTransferuj($cart, $order, $auto_redirect = false, $form_method = "GET")
	{
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null; // Inna metoda została wybrana, nie rób nic.
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}

		if (!class_exists('VirtueMartModelOrders'))
		{
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		}
		
		// konwersja do PLN
		$this->getPaymentCurrency($method);
		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);		
		$kwota_zamowienia = number_format($paymentCurrency->convertCurrencyTo(114, $order['details']['BT']->order_total, false), 2, ".", ""); 

		// zmienne
        $zamowienie = $order['details']['BT'];
		$session_id = md5($zamowienie->order_number.'|'.time());
		
        $q = 'SELECT country_3_code FROM #__virtuemart_countries WHERE virtuemart_country_id='.$zamowienie->virtuemart_country_id.' ';        // kraj 3 znakowo
        $db = JFactory::getDBO();
        $db->setQuery($q);
        $country = $db->loadResult();
		
		$md5sum = md5($method->transferuj_id.$kwota_zamowienia.$session_id.$method->transferuj_kod_potwierdzajacy);
		
		$this->_virtuemart_paymentmethod_id = $zamowienie->virtuemart_paymentmethod_id;
		$dbWartosci['order_number'] = $zamowienie->order_number;
		$dbWartosci['payment_name'] = $this->renderPluginName($method, $order);
		$dbWartosci['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
		$dbWartosci['tax_id'] = $method->tax_id;
		
		// wartości Transferuj.pl
		$dbWartosci['transferuj_crc'] = $session_id;
        $dbWartosci['kwota_zamowienia'] = $kwota_zamowienia ;

		// zapisz do bazy
		$this->storePSPluginInternalData($dbWartosci);                    		
		
		// zawartośc HTML na podstronie potwierdzenia zamówienia 
		//Numer zamówienia: '.$order['details']['BT']->order_number.'
		
		$html = '
		<div style="text-align: center; width: 100%; ">
		<form action="https://secure.transferuj.pl" method="'.$form_method.'" class="form" name="platnosc_transferuj" id="platnosc_transferuj">
			<input type="hidden" name="id" value="'.$method->transferuj_id.'" />
			<input type="hidden" name="kwota" value="'.$kwota_zamowienia.'" />
			<input type="hidden" name="opis" value="Zamówienie nr '.$order['details']['BT']->order_number.'" />
			<input type="hidden" name="crc" value="'.$session_id.'" />
			<input type="hidden" name="md5sum" value="'.$md5sum.'" />
            <input type="hidden" name="online" value="'.$method->transferuj_online.'" />
            <input type="hidden" name="wyn_url" value="'.$method->transferuj_wyn_url.'" />
            <input type="hidden" name="pow_url" value="'.str_replace("confirm","ok",$method->transferuj_wyn_url).'" />
			<input type="hidden" name="pow_url_blad" value="'.str_replace("confirm","error",$method->transferuj_wyn_url).'" />

            <input type="hidden" name="email" value="'.$zamowienie->email.'" />
            <input type="hidden" name="imie" value="'.$zamowienie->first_name.'" />
            <input type="hidden" name="nazwisko" value="'.$zamowienie->last_name.'" />
			<input type="hidden" name="adres" value="'.$zamowienie->address_1.'" />
            <input type="hidden" name="miasto" value="'.$zamowienie->city.'" />
            <input type="hidden" name="kod" value="'.$zamowienie->zip.'" />
            <input type="hidden" name="kraj" value="'.$country.'" />
            <input type="hidden" name="telefon" value="'.$zamowienie->phone_1.'" />
			';
		
		switch($method->transferuj_formularz)
		{
			case 1:
				// wyświetlaj formularz z kanałami na stronie tylko na podstronie złożenia zamówienia
				if($_REQUEST['view']=='cart')
				{
					$doc =& JFactory::getDocument();
					$html .=  "<div id='transferuj_content'></div>";
					$doc->addScript("https://secure.transferuj.pl/channels-".$method->transferuj_id.$method->transferuj_online.".js");
					if(version_compare(JVERSION,'1.6.0','ge'))
						$doc->addScript("plugins/vmpayment/transferuj/js/showchannels.js");
					else
						$doc->addScript("plugins/vmpayment/js/showchannels.js");					
					
					$cssChannels = "
					#kanaly div.selected {
						border: 2px solid #1E63A9;
						border-radius: 4px 4px 4px 4px;
						margin: 3px 3px -1px -1px;
					}

					#kanaly div {
						background-position: left top;
						background-repeat: no-repeat;
						border: 1px solid #DADADA;
						cursor: pointer;
						float: left;
						height: 88px;
						margin-right: 4px;
						margin-top: 4px;
						padding: 5px;
						position: relative;
						width: 127px;
						z-index: 4;
					}

					#kanaly div p.label {
						border: 0 none;
						bottom: 0;
						color: #345565;
						cursor: pointer;
						font-size: 0.625em;
						font-weight: bold;
						left: 0;
						margin: 0;
						padding: 0 0 3px;
						position: absolute;
						right: 0;
						text-align: center;
					}
					";
					$doc->addStyleDeclaration($cssChannels);
					
					// skok do 2 kroku
					$html .= '<input type="hidden" name="akceptuje_regulamin" value="1" />';
					
					$html .= '
					<script type="text/javascript">
						ShowChannels();
					</script>';					
					
					break;
				}
			case 0:
				// button
				if(file_exists(JPATH_BASE.DS.'images'.DS.'stories'.DS.'virtuemart'.DS.'payment'.DS.$method->payment_image))
				{
					$pic = getimagesize(JPATH_BASE.DS.'images/stories/virtuemart/payment/'.$method->payment_image);
					$html .= '		  
				  <input name="submit_send" value="" type="submit" style="border: 0; background: url(\''.JURI::root().'images/stories/virtuemart/payment/'.$method->payment_image.'\'); width: '.$pic[0].'px; height: '.$pic[1].'px; cursor: pointer;" /> ';
				}
				else
				{
					$html .= '<input name="submit_send" value="Zapłać z Transferuj.pl" type="submit"  style="padding: 10px 20px;" /> ';
				}
				
				// automatyczne przerzucenie do płatności
				if($method->autoredirect && $auto_redirect)
				{
					$html .= '
					<script type="text/javascript">
						window.addEvent("load", function() { 
							document.getElementById("platnosc_transferuj").submit();
							});
					</script>';
				}
				
				$html .= '<p style="text-align: center; width: 100%; ">'.$method->checkout_text.'</p>';
			break;
		}
		
		$html .= '</form></div>';
		
		return $html;
	}
	
	function plgVmConfirmedOrder($cart, $order)
	{
		// jeżeli nie zwraca $html - wyrzuc false
		if (!($html = $this->plgVmPotwierdzenieTransferuj($cart, $order, true, "POST"))) {
			return false; 
		}
		
		// nazwa płatnosci - zmiana dla Joomla 2.5 !!!
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) 
		{
			return null;
		}
		$nazwa_platnosci = $this->renderPluginName($method);
		
		// tutaj w vm 2.0.2 trzeba dodać status na końcu, zeby się nie wywalało
		return $this->processConfirmedOrderPaymentResponse(1, $cart, $order, $html, $nazwa_platnosci, $method->status_pending);
	}
	
	// zdarzenie po otrzymaniu poprawnego lub błędnego url'a z systemu payu
	function plgVmOnPaymentResponseReceived(&$html) 
	{
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; 
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		
		if(isset($_GET['status']) && !empty($_GET['status']))
		{
			switch($_GET['status'])
			{
				case "ok":
					// pozytywna
					JFactory::getApplication()->enqueueMessage( 'Dziękujemy za dokonanie transakcji za pośrednictwem Transferuj.pl.' );
					return true;
				break; 
				
				case "error":
					// negatywna
					JError::raiseWarning( 100, 'Wystąpił błąd w trakcie składania płatności za pośrednictwem Transferuj.pl.');
					return true;
				break;
				
				case "confirm":
					// potwierdzenie
					
					// IP
					if($_SERVER['REMOTE_ADDR']=="195.149.229.109")
					{						
						// dane z requesta Transferuj
						$payment_data = $_POST;
						
						// porównanie z bazą
						$db = &JFactory::getDBO();
						$q = 'SELECT transferuj.*, ord.order_status, usr.email  FROM '.$this->_tablename.' as transferuj JOIN `#__virtuemart_orders` as ord using(virtuemart_order_id) JOIN #__virtuemart_order_userinfos  as usr using(virtuemart_order_id)  WHERE transferuj.transferuj_crc="' .$payment_data['tr_crc']. '" ';
						
						$db->setQuery($q);
						$payment_db = $db->loadObject();

						if(!empty($payment_db))
						{
							
							$md5_request = $payment_data['md5sum'];
				
							$md5_vm["id"] = $method->transferuj_id;
							$md5_vm["tr_id"] = $payment_data["tr_id"];
							$md5_vm["tr_amount"] = $payment_data["tr_amount"];
							$md5_vm["tr_crc"] = $payment_data['tr_crc'];
							$md5_vm["kod"] = $method->transferuj_kod_potwierdzajacy;
							$md5_db = md5(implode("",$md5_vm));

							// porównanie sum kontrolnych
							if($md5_request==$md5_db)
							{
								echo "TRUE\r\n";
								
								// tr_error
								$error_msg="";
								if($payment_data['tr_error']=="overpay")
									$error_msg = "<br><b>Dokonano nadpłaty.</b>";
								else if($payment_data['tr_error']=="surcharge")
									$error_msg = "<br><b>Dokonano niedopłaty.</b>";
									
								
								// poprawna
								if($payment_data['tr_status']=="TRUE")
								{
									
									if($payment_db->order_status!="C" && $payment_db->order_status!='X')
									{
										$virtuemart_order_id = $payment_db->virtuemart_order_id;
										$message = 'Płatność została potwierdzona.';
										if(!empty($error_msg))
										{
											$message .= $error_msg;
						
						}
										if(($status = $this->nowyStatus($virtuemart_order_id,$method->status_success, $message, $method->powiadomienia))==false)
										{
											$this->logInfo('plgVmOnPaymentResponseReceived Bład podczas zmiany statusu zamówienia na '.$method->status_success);
										}
										else
										{
											$this->logInfo('plgVmOnPaymentResponseReceived Potwierdzono zmianę statusu zamówienia na '.$method->status_success);
										}
									}
								}
								else if ($payment_data['tr_status']=="FALSE")
								{
									if($payment_db->order_status!="C" && $payment_db->order_status!='X')
									{
										$virtuemart_order_id = $payment_db->virtuemart_order_id;
										$message = 'Płatność została oznaczona jako błędna.';
										if(!empty($error_msg))
										{
											$message .= $error_msg;
										}
										
										if(($status = $this->nowyStatus($virtuemart_order_id,$method->status_canceled, $message, $method->powiadomienia))==false)
										{
											$this->logInfo('plgVmOnPaymentResponseReceived Bład podczas zmiany statusu zamówienia na '.$method->status_canceled);
										}
										else
										{
											$this->logInfo('plgVmOnPaymentResponseReceived Potwierdzono zmianę statusu zamówienia na '.$method->status_canceled);
										}
									}
								}
								
								exit();	
							}
							else
							{
								$this->logInfo('plgVmOnPaymentResponseReceived Sumy kontrolne nie są identyczne.');
							}
						}
						else
						{
							$this->logInfo('plgVmOnPaymentResponseReceived Pusty rekord pobierania informacji nt. zamówienia z bazy danych.');
						}						
						
					}
				break;
			}
		}

        		
	}


	
	// wyświetl dane płatności dla zamówienia (backend)
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) 
	{
		if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
			return null; // Another method was selected, do nothing
		}

		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
			vmWarn(500, $q . " " . $db->getErrorMsg());
			return '';
		}
		$this->getPaymentCurrency($paymentTable);

		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', number_format($paymentTable->kwota_zamowienia,2,".","").' '.$paymentTable->waluta_platnosci);
		$html .= '</table>' . "\n";
		return $html;
    }
	
	
	// moja funkcja nowego statusu
	function nowyStatus($virtuemart_order_id, $nowy_status, $notatka = "",  $wyslij_powiadomienie=1)
	{
			if (!class_exists('VirtueMartModelOrders'))
			{
				require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			}
			
			// załadowanie języka dla templatey zmiany statusu zam. z admina!
			$lang = &JFactory::getLanguage();		
			$lang->load('com_virtuemart',JPATH_ADMINISTRATOR);
			
			$modelOrder = VmModel::getModel('orders');
			$zamowienie = $modelOrder->getOrder($virtuemart_order_id);
			if(empty($zamowienie))
			{
				return false;
			}
			
			$order['order_status'] = $nowy_status;
			$order['virtuemart_order_id'] = $virtuemart_order_id;
			$order['customer_notified'] = $wyslij_powiadomienie;
			$order['comments'] = $notatka;
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);

			// last modify + lock płatności w BD			
			$db = &JFactory::getDBO();
			// sql'e zależne od nowego statusu
			
			if($nowy_status=="C" || $nowy_status=="X")
			{
				$q = 'UPDATE '.$this->_tablename.' SET modified_on=NOW(), locked_on=NOW() WHERE virtuemart_order_id='.$virtuemart_order_id.';   ';		
			}
			else
			{
				$q = 'UPDATE '.$this->_tablename.' SET modified_on=NOW() WHERE virtuemart_order_id='.$virtuemart_order_id.';   ';
			}

			$db->setQuery($q);
			$wynik = $db->query($q);
			
			if(empty($wynik))
			{
				return false;
			}

			$message = 'Status zamówienia zmienił się.';


			
			return $message;
	}
	
	// sprawdź czy płatność spełnia wymagania
	protected function checkConditions($cart, $method, $cart_prices) 
	{
		return true;
	}
	
	
	/*
	*
	*	RESZTA METOD
	*
	*/
	
	
	protected function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment Transferuj Table');
    }
	
	// utwórz opcjonalnie tabelę płatności, zapisz dane z xml'a itp.
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) 
	{
		return $this->onStoreInstallPluginTable($jplugin_id);
    }
	
	// zdarzenie po wyborze płatności (front)
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) 
	{
		return $this->OnSelectCheck($cart);
    }
		
	// zdarzenie wywoływane podczas listowania płatności
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) 
	{
		return $this->displayListFE($cart, $selected, $htmlIn);
    }
	
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) 
	{
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) 
	{
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		 $this->getPaymentCurrency($method);

		$paymentCurrencyId = $method->payment_currency;
    }
	
	// sprawdza ile pluginów płatności jest dostepnych, jeśli tylko jeden, użytkownik nie ma wyboru
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) 
	{
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }
	
	// zdarzenie wywoływane podczas przeglądania szczegółów zamówienia (front)
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) 
	{	
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
	
	 // funkcja wywołująca stricte zawartość komórki payment w szczegółach zamówienia (front - konto usera)
	 function onShowOrderFE($virtuemart_order_id, $virtuemart_method_id, &$method_info)
	 {
	 	if (!($this->selectedThisByMethodId($virtuemart_method_id))) {
			return null;
		}
	 
		// ograniczenie generowania się dodatkowego fomrularza, jeśli klient nie opłacił jeszcze zamówienia, tylko do szczegółów produktu
		// dodatkowo w zależności od serwera, tworzenie faktury w PDF głupieje czasami przy obrazkach dla płatności 
		if(isset($_REQUEST['view']) && $_REQUEST['view']=='orders' && isset($_REQUEST['layout']) && $_REQUEST['layout']=='details')
		{
			// wywołaj cały formularz
			if (!class_exists('VirtueMartModelOrders'))
			{
				require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			}
			if (!class_exists('VirtueMartCart'))
			{
				require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
			}	
			if (!class_exists('CurrencyDisplay'))
			{
				require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
			}
			$modelOrder = new VirtueMartModelOrders();
			$cart = VirtueMartCart::getCart();
			$order = $modelOrder->getOrder($virtuemart_order_id);

			
			if (!($html = $this->plgVmPotwierdzenieTransferuj($cart, $order, false ,"POST")) || $order['details']['BT']->order_status=='C' || $order['details']['BT']->order_status=='U' ) 
			{			
				$method_info = $this->getOrderMethodNamebyOrderId($virtuemart_order_id);
			}
			else
			{
				$method_info = $html;
			}
		}
		else
		{
			$method_info = 'Transferuj.pl';
		}
	 }
	 
	 // pobranie nazwy płatności z bazy 
	function getOrderMethodNamebyOrderId ($virtuemart_order_id) {

		$db = JFactory::getDBO ();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id.  ' ORDER BY id DESC LIMIT 1 ';
		$db->setQuery ($q);
		if (!($pluginInfo = $db->loadObject ())) {
			vmWarn ('Attention, ' . $this->_tablename . ' has not any entry for the order ' . $db->getErrorMsg ());
			return NULL;
		}
		$idName = $this->_psType . '_name';

		return $pluginInfo->$idName;
	}
	
	 /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */

	// wymagane aby zapis XML'a do BD działał
	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
	return $this->onShowOrderPrint($order_number, $method_id);
    }

	// wymagane aby zapis XML'a do BD działał
    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
	
		// nadpisujemy parametr , aby edycja nic mu nie robiła!
		$virtuemart_paymentmethod_id = $_GET['cid'][0];
		$urlc = 'transferuj_wyn_url="'.JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm='.$virtuemart_paymentmethod_id.'&status=confirm"|';
        $data->payment_params .= $urlc;
		
		return $this->declarePluginParams('payment', $name, $id, $data);
    }

	// wymagane aby zapis XML'a do BD działał
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
	return $this->setOnTablePluginParams($name, $id, $table);
    }
}