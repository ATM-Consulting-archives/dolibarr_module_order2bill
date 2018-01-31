<?php

if (!class_exists('TObjetStd'))
{
	/**
	 * Needed if $form->showLinkedObjectBlock() is call
	 */
	define('INC_FROM_DOLIBARR', true);
	require_once dirname(__FILE__).'/../config.php';
}


class Order2Bill
{

	function generate_factures($commandes, $dateFact=0, $show_trace = true)
	{
		global $conf, $langs, $db, $user;

		// Inclusion des classes nécessaires
		dol_include_once('/commande/class/commande.class.php');
		dol_include_once('/compta/facture/class/facture.class.php');
		dol_include_once('/core/modules/facture/modules_facture.php');

		// Utilisation du module livraison
		if($conf->livraison_bon->enabled) {
			dol_include_once('/livraison/class/livraison.class.php');
		}
		// Utilisation du module sous-total si activé
		if($conf->subtotal->enabled) {
			dol_include_once('/subtotal/class/actions_subtotal.class.php');
			dol_include_once('/subtotal/class/subtotal.class.php');
			$langs->load("subtotal@subtotal");
			$sub = new ActionsSubtotal($db);
		}

		// Option pour la génération PDF
		$hidedetails = (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS) ? 1 : 0);
		$hidedesc = (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DESC) ? 1 : 0);
		$hideref = (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_REF) ? 1 : 0);

		if(empty($dateFact)) {
			$dateFact = dol_now();
		}

		$nbFacture = 0;
		$TFiles = array();

		//unset les id expédition qui sont déjà liés à une facture
		$this->_clearElementAlreadyLinked($db, $commandes);

		// Pour chaque id client
		foreach($commandes as $id_client => $Tids)
		{
			if (empty($Tids)) continue;

			// Création d'une facture regroupant plusieurs expéditions (par défaut)
			if(empty($conf->global->ORDER2BILL_INVOICE_PER_ORDER)) {
				$f = $this->facture_create($id_client, $dateFact);
				$nbFacture++;
			}

			// Pour chaque id expédition
			foreach($Tids as $fk_object => $val) {

				if($show_trace) echo $fk_object.'...';

				// Chargement de l'expédition
				$ord = new Commande($db);
				$ord->fetch($fk_object);

				// Création d'une facture par expédition si option activée
				if(!empty($conf->global->ORDER2BILL_INVOICE_PER_ORDER)) {
					$f = $this->facture_create($id_client, $dateFact);
					$f->note_public = $ord->note_public;
					$f->note_private = $ord->note_private;
					$f->update($user);
					$nbFacture++;
				}

				// Ajout pour éviter déclenchement d'autres modules, par exemple ecotaxdee
				$f->context = array('origin'=>'shipping', 'origin_id'=>$id_exp);

				// Ajout du titre
				$this->facture_add_title($f, $ord, $sub);
				// Ajout des lignes
				$this->facture_add_line($f, $ord);
				// Ajout du sous-total
				$this->facture_add_subtotal($f, $sub);
				// Lien avec la facture
				$res = $f->add_object_linked('commande', $ord->id);
				if($res<=0) {
					var_dump($res,$id_client,$fk_object,$f->db->lastquery,$f->error);exit;

				}

				// Clôture de l'expédition
				if($conf->global->ORDER2BILL_CLOSE_ORDER) {
					$ord->classifyBilled($user);
					$ord->cloture($user);
				}
			}

			// Ajout notes sur facture si une seule expé
			if(count($Tid_exp) == 1) {
				if (!empty($ord->note_public)) $f->update_note($ord->note_public, '_public');
			}

			// Validation de la facture
			if($conf->global->ORDER2BILL_VALID_INVOICE) $f->validate($user, '', $conf->global->ORDER2BILL_WARHOUSE_TO_USE);
			if($show_trace){ echo $f->id.'|';flush(); }
			// Génération du PDF
			if(!empty($conf->global->ORDER2BILL_GENERATE_INVOICE_PDF)) $TFiles[] = $this->facture_generate_pdf($f, $hidedetails, $hidedesc, $hideref);
		}

		if($conf->global->ORDER2BILL_GENERATE_GLOBAL_PDF) $this->generate_global_pdf($TFiles);

		return $nbFacture;
	}

	private function _clearElementAlreadyLinked(&$db, &$commandes)
	{
		foreach($commandes as $id_client => &$Tid_exp)
		{
			foreach($Tid_exp as $id_exp => $val)
			{
				$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'element_element WHERE sourcetype="commande" AND fk_source='.(int) $id_exp.' AND targettype="facture"';

				$resql = $db->query($sql);
				if ($resql)
				{
					if ($db->num_rows($resql) > 0)
					{
						unset($Tid_exp[$id_exp]);
					}
				}

			}

		}
	}

	function facture_create($id_client, $dateFact) {
		global $user, $db, $conf;

		$f = new Facture($db);

		// Si le module Client facturé est activé et que la constante BILLANOTHERCUSTOMER_USE_PARENT_BY_DEFAULT est à 1, on facture la maison mère
		if($conf->billanothercustomer->enabled && $conf->global->BILLANOTHERCUSTOMER_USE_PARENT_BY_DEFAULT) {
			$soc = new Societe($db);
			$soc->fetch($id_client);
			if($soc->parent > 0)
				$id_client = $soc->parent;
		}

		$f->socid = $id_client;
		$f->fetch_thirdparty();

		// Données obligatoires
		$f->date = $dateFact;
		$f->type = 0;
		$f->cond_reglement_id = (!empty($f->thirdparty->cond_reglement_id) ? $f->thirdparty->cond_reglement_id : 1);
		$f->mode_reglement_id = $f->thirdparty->mode_reglement_id;
		$f->modelpdf = !empty($conf->global->ORDER2BILL_GENERATE_INVOICE_PDF) ? $conf->global->ORDER2BILL_GENERATE_INVOICE_PDF : 'einstein';
		$f->statut = 0;

		//Récupération du compte bancaire si mode de règlement = VIR
		if (!empty($conf->global->ORDER2BILL_USE_DEFAULT_BANK_IN_INVOICE_MODULE) && !empty($conf->global->FACTURE_RIB_NUMBER) && $this->getModeReglementCode($db , $f->mode_reglement_id) == 'VIR')
		{
			$f->fk_account = $conf->global->FACTURE_RIB_NUMBER;
		}

		$f->create($user);

		return $f;
	}

	function getModeReglementCode(&$db, $mode_reglement_id)
	{
		if ($mode_reglement_id <= 0) return '';

		$code = '';
		$sql = 'SELECT code FROM '.MAIN_DB_PREFIX.'c_paiement WHERE id = '.(int) $mode_reglement_id;
		$resql = $db->query($sql);
		if ($resql && ($row = $db->fetch_object($resql))) $code = $row->code;

		return $code;
	}

	function facture_add_line(Facture &$f, &$ord) {
		global $conf, $db;

		// Pour chaque produit de l'expédition, ajout d'une ligne de facture
		foreach($ord->lines as $l){
			if($conf->global->SHIPMENT_GETS_ALL_ORDER_PRODUCTS && $l->qty == 0) continue;
			// Sélectionne uniquement les produits

			// Si ligne du module sous-total et que sa description est vide alors il faut attribuer le label (le label ne semble pas être utiliser pour l'affichage car deprécié)
			if (!empty($conf->subtotal->enabled) && $l->special_code == TSubtotal::$module_number && empty($l->description)) $l->description = $l->label;
			//addline($desc, $pu_ht, $qty, $txtva, $txlocaltax1=0, $txlocaltax2=0, $fk_product=0, $remise_percent=0, $date_start='', $date_end='', $ventil=0, $info_bits=0, $fk_remise_except='', $price_base_type='HT', $pu_ttc=0, $type=self::TYPE_STANDARD, $rang=-1, $special_code=0, $origin='', $origin_id=0, $fk_parent_line=0, $fk_fournprice=null, $pa_ht=0, $label='', $array_options=0, $situation_percent=100, $fk_prev_id='', $fk_unit = null, $pu_ht_devise = 0)
			$res = $f->addline($l->description, (double)$l->subprice, (double)$l->qty, (double)$l->tva_tx,(double)$l->localtax1tx,(double)$l->localtax2tx,(int)$l->fk_product, (double)$l->remise_percent,'','',0,0,'','HT',0,(int)$l->product_type,-1,(int)$l->special_code,'',0,0,(int)$l->fk_fournprice,(double)$l->pa_ht,$l->label,$l->array_options);

			if($res<=0) {
				var_dump($f);exit;
			}

		}

	}

	function facture_add_title (Facture &$f, Commande &$ord, &$sub) {
		global $conf, $langs, $db;

		// Affichage des références expéditions en tant que titre
		if($conf->global->ORDER2BILL_ADD_ORDER_AS_TITLES) {
			$title = '';

			$title.= $langs->transnoentities('Order').' '.$ord->ref;
			if(!empty($ord->ref_client)) $title.= ' / '.$ord->ref_client;
			if(!empty($ord->date)) $title.= ' ('.dol_print_date($ord->date,'day').')';

			if($ord->socid > 0 && $conf->global->ORDER2BILL_DISPLAY_ORDERCUSTOMER_IN_TITLE) {
				$soc = new Societe($db);
				$soc->fetch($ord->socid);

				$title.= ' - '.$soc->name.' '.$soc->zip.' '.$soc->town;
			}

			if($conf->subtotal->enabled) {
				if(method_exists($sub, 'addSubTotalLine')) $sub->addSubTotalLine($f, $title, 1);
				else {
					if((float)DOL_VERSION <= 3.4) $f->addline($f->id, $title, 0,1,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
					else $f->addline($title, 0,1,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
				}
			} else {
				if((float)DOL_VERSION <= 3.4) $f->addline($f->id, $title, 0, 1, 0);
				else $f->addline($title, 0, 1);
			}
		}
	}

	function facture_add_subtotal(&$f,&$sub) {
		global $conf, $langs;

		// Ajout d'un sous-total par expédition
		if($conf->global->ORDER2BILL_ADD_SHIPMENT_SUBTOTAL) {
			if($conf->subtotal->enabled) {
				if(method_exists($sub, 'addSubTotalLine')) $sub->addSubTotalLine($f, $langs->transnoentities('SubTotal'), 99);
				else {
					if((float)DOL_VERSION <= 3.4) $f->addline($f->id, $langs->transnoentities('SubTotal'), 0,99,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
					else $f->addline($langs->transnoentities('SubTotal'), 0,99,0,0,0,0,0,'','',0,0,'','HT',0,9,-1, 104777);
				}
			}
		}
	}

	function facture_generate_pdf(&$f, $hidedetails, $hidedesc, $hideref) {
		global $conf, $langs, $db;

		// Il faut recharger les lignes qui viennent juste d'être créées
		$f->fetch($f->id);

		$outputlangs = $langs;
		if ($conf->global->MAIN_MULTILANGS) {$newlang=$f->client->default_lang;}
		if (! empty($newlang)) {
			$outputlangs = new Translate("",$conf);
			$outputlangs->setDefaultLang($newlang);
		}

		if ((float) DOL_VERSION <= 4.0)	$result=facture_pdf_create($db, $f, $f->modelpdf, $outputlangs, $hidedetails, $hidedesc, $hideref);
		else $result = $f->generateDocument($f->modelpdf, $outputlangs, $hidedetails, $hidedesc, $hideref);

		if($result > 0) {
			$objectref = dol_sanitizeFileName($f->ref);
			$dir = $conf->facture->dir_output . "/" . $objectref;
			$file = $dir . "/" . $objectref . ".pdf";
			return $file;
		}

		return '';
	}

	function generate_global_pdf($TFiles)
	{
		global $langs, $conf;

		dol_include_once('/core/lib/pdf.lib.php');

        // Create empty PDF
        $pdf=pdf_getInstance();
        if (class_exists('TCPDF'))
        {
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
        }
        $pdf->SetFont(pdf_getPDFFont($langs));

        if (! empty($conf->global->MAIN_DISABLE_PDF_COMPRESSION)) $pdf->SetCompression(false);

		// Add all others
		foreach($TFiles as $file)
		{
			// Charge un document PDF depuis un fichier.
			$pagecount = $pdf->setSourceFile($file);
			for ($i = 1; $i <= $pagecount; $i++)
			{
				$tplidx = $pdf->importPage($i);
				$s = $pdf->getTemplatesize($tplidx);
				$pdf->AddPage($s['h'] > $s['w'] ? 'P' : 'L');
				$pdf->useTemplate($tplidx);
			}
		}

		// Create output dir if not exists
		$diroutputpdf = $conf->order2bill->multidir_output[$conf->entity];
		dol_mkdir($diroutputpdf);

		// Save merged file
		$filename=strtolower(dol_sanitizeFileName($langs->transnoentities("OrderBilled")));
		if ($pagecount)
		{
			$now=dol_now();
			$file=$diroutputpdf.'/'.$filename.'_'.dol_print_date($now,'dayhourlog').'.pdf';
			$pdf->Output($file,'F');
			if (! empty($conf->global->MAIN_UMASK))
			@chmod($file, octdec($conf->global->MAIN_UMASK));
		}
		else
		{
			setEventMessage($langs->trans('NoPDFAvailableForChecked'),'errors');
		}
	}

}


