<?php
/* Copyright (C) 2001-2005 Rodolphe Quiedeville   	<rodolphe@quiedeville.org>
 * Copyright (C) 2004-2013 Laurent Destailleur   	<eldy@users.sourceforge.net>
 * Copyright (C) 2005      Marc Barilley / Ocebo  	<marc@ocebo.com>
 * Copyright (C) 2005-2012 Regis Houssin          	<regis.houssin@capnetworks.com>
 * Copyright (C) 2012	   Andreu Bisquerra Gaya  	<jove@bisquerra.com>
 * Copyright (C) 2012	   David Rodriguez Martinez <davidrm146@gmail.com>
 * Copyright (C) 2012-2017 Juanjo Menent			<jmenent@2byte.es>
 * Copyright (C) 2015	   Ferran Marcet			<fmarcet@2byte.es>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *	\file       htdocs/commande/orderstoinvoice.php
 *	\ingroup    commande
 *	\brief      Page to invoice multiple orders
 */

require '../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/report.lib.php';
require 'class/order2bill.class.php';

if (! empty($conf->projet->enabled)) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
}

$langs->load('orders');
$langs->load('deliveries');
$langs->load('companies');

if (! $user->rights->facture->creer)
	accessforbidden();

$id				= (GETPOST('id')?GETPOST('id','int'):GETPOST("facid","int"));  // For backward compatibility
$ref			= GETPOST('ref','alpha');
$action			= GETPOST('action','alpha');
$confirm		= GETPOST('confirm','alpha');
$sref			= GETPOST('sref');
$sref_client	= GETPOST('sref_client');
$sall			= GETPOST('sall', 'alphanohtml');
$socid			= GETPOST('socid','int');
$selected		= GETPOST('orders_to_invoice');
$sortfield		= GETPOST("sortfield",'alpha');
$sortorder		= GETPOST("sortorder",'alpha');
$viewstatut		= GETPOST('viewstatut');

$error = 0;

if (! $sortfield) $sortfield='c.rowid';
if (! $sortorder) $sortorder='DESC';

$now = dol_now();
$date_start = dol_mktime(0,0,0,$_REQUEST["date_startmonth"],$_REQUEST["date_startday"],$_REQUEST["date_startyear"]);	// Date for local PHP server
$date_end = dol_mktime(23,59,59,$_REQUEST["date_endmonth"],$_REQUEST["date_endday"],$_REQUEST["date_endyear"]);
$date_starty = dol_mktime(0,0,0,$_REQUEST["date_start_delymonth"],$_REQUEST["date_start_delyday"],$_REQUEST["date_start_delyyear"]);	// Date for local PHP server
$date_endy = dol_mktime(23,59,59,$_REQUEST["date_end_delymonth"],$_REQUEST["date_end_delyday"],$_REQUEST["date_end_delyyear"]);

$extrafields = new ExtraFields($db);

// fetch optionals attributes and labels
$extralabels=$extrafields->fetch_name_optionals_label('facture');

//Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
$hookmanager=new HookManager($db);
$hookmanager->initHooks(array('orderstoinvoice'));


/*
 * Actions
 */

if (($action == 'create' || $action == 'add') && !$error)
{
	$orders= GETPOST('orders_to_invoice');
	$dateFact = GETPOST('dtfact');
	if(empty($dateFact)) {
		$dateFact = dol_now();
	} else {
		$dateFact = dol_mktime(0, 0, 0, GETPOST('dtfactmonth'), GETPOST('dtfactday'), GETPOST('dtfactyear'));
	}
	
	if(empty($orders)) {
		setEventMessage($langs->trans('NoOrderSelected'), 'warnings');
	} else {
		$o= new Order2Bill();
		$nbFacture = $o->generate_factures($orders, $dateFact,false);
		 
		setEventMessage($langs->trans('InvoiceCreated', $nbFacture));
		
		header("Location: ".$_SERVER['PHP_SELF']);
		
	}
	
	exit;
	
}

$html=new Form($db);
$formfile=new FormFile($db);

// Mode liste
if (($action != 'create' && $action != 'add') || ($action == 'create' && $error))
{
	llxHeader();
	?>
	<script type="text/javascript">
		jQuery(document).ready(function() {
		jQuery("#checkall").click(function() {
			jQuery(".checkformerge").prop('checked', true);
		});
		jQuery("#checknone").click(function() {
			jQuery(".checkformerge").prop('checked', false);
		});
	});
	</script>
	<?php

	$sql = 'SELECT s.nom, s.rowid as socid,c.fk_soc, s.client, c.rowid, c.ref, c.total_ht, c.ref_client,';
	$sql.= ' c.date_valid, c.date_commande, c.date_livraison, c.fk_statut, c.facture as facturee';
	$sql.= ' FROM '.MAIN_DB_PREFIX.'societe as s';
	$sql.= ', '.MAIN_DB_PREFIX.'commande as c';
	if (!$user->rights->societe->client->voir && !$socid) $sql.= ", ".MAIN_DB_PREFIX."societe_commerciaux as sc";
	$sql.= ' WHERE c.entity IN ('.getEntity('commande').')';
	$sql.= ' AND c.fk_soc = s.rowid';

	// Show orders with status validated, shipping started and delivered (well any order we can bill)
	$sql.= " AND ((c.fk_statut IN (1,2)) OR (c.fk_statut = 3 AND c.facture = 0))";

	if ($socid)	$sql.= ' AND s.rowid = '.$socid;
	if (!$user->rights->societe->client->voir && !$socid) $sql.= " AND s.rowid = sc.fk_soc AND sc.fk_user = " .$user->id;
	if ($sref)
	{
		$sql.= " AND c.ref LIKE '%".$db->escape($sref)."%'";
	}
	if ($sall)
	{
		$sql.= " AND (c.ref LIKE '%".$db->escape($sall)."%' OR c.note LIKE '%".$db->escape($sall)."%')";
	}

	//Date filter
	if ($date_start && $date_end) $sql.= " AND c.date_commande >= '".$db->idate($date_start)."' AND c.date_commande <= '".$db->idate($date_end)."'";
	if ($date_starty && $date_endy) $sql.= " AND c.date_livraison >= '".$db->idate($date_starty)."' AND c.date_livraison <= '".$db->idate($date_endy)."'";

	if (!empty($sref_client))
	{
		$sql.= ' AND c.ref_client LIKE \'%'.$db->escape($sref_client).'%\'';
	}
	$sql.= ' ORDER BY '.$sortfield.' '.$sortorder;
	$resql = $db->query($sql);

	if ($resql)
	{
		if ($socid)
		{
			$soc = new Societe($db);
			$soc->fetch($socid);
		}
		$title = $langs->trans('ListOfOrders');
		$title.=' - '.$langs->trans('StatusOrderValidated').', '.$langs->trans("StatusOrderSent").', '.$langs->trans('StatusOrderToBill');
		$num = $db->num_rows($resql);
		print load_fiche_titre($title);
		$i = 0;
		$period=$html->select_date($date_start,'date_start',0,0,1,'',1,0,1).' - '.$html->select_date($date_end,'date_end',0,0,1,'',1,0,1);
		$periodely=$html->select_date($date_starty,'date_start_dely',0,0,1,'',1,0,1).' - '.$html->select_date($date_endy,'date_end_dely',0,0,1,'',1,0,1);

		if (! empty($socid))
		{
			// Company
			$companystatic->id=$socid;
			$companystatic->name=$soc->name;
			print '<h3>'.$companystatic->getNomUrl(1,'customer').'</h3>';
		}

		print '<table class="noborder" width="100%">';
		print '<tr class="liste_titre">';
		print_liste_field_titre('Ref',$_SERVER["PHP_SELF"],'c.ref','','','',$sortfield,$sortorder);
		print_liste_field_titre('Customer',$_SERVER["PHP_SELF"],'c.fk_soc','','','',$sortfield,$sortorder);
		print_liste_field_titre('RefCustomerOrder',$_SERVER["PHP_SELF"],'c.ref_client','','&amp;socid='.$socid,'',$sortfield,$sortorder);
		print_liste_field_titre('OrderDate',$_SERVER["PHP_SELF"],'c.date_commande','','&amp;socid='.$socid, 'align="center"',$sortfield,$sortorder);
		print_liste_field_titre('DeliveryDate',$_SERVER["PHP_SELF"],'c.date_livraison','','&amp;socid='.$socid, 'align="center"',$sortfield,$sortorder);
		print_liste_field_titre('Status','','','','','align="right"');
		print_liste_field_titre('GenerateBill','','','','','align="center"');
		print '</tr>';

		// Lignes des champs de filtre
		print '<form method="post" action="orderstoinvoice.php">';
		print '<input type="hidden" name="socid" value="'.$socid.'">';
		print '<tr class="liste_titre">';
		print '<td class="liste_titre">';
		//REF
		print '<input class="flat" size="10" type="text" name="sref" value="'.$sref.'">';
		print '</td>';
		print '<td class="liste_titre">&nbsp;';
		
		print '</td>';
		
		print '<td class="liste_titre" align="left">';
		print '<input class="flat" type="text" size="10" name="sref_client" value="'.$sref_client.'">';
        print '</td>';

		//DATE ORDER
		print '<td class="liste_titre" align="center">';
		print $period;
		print '</td>';

		//DATE DELIVERY
		print '<td class="liste_titre" align="center">';
		print $periodely;
		print '</td>';

		//SEARCH BUTTON
		print '<td align="right" class="liste_titre">';
		print '<input type="image" class="liste_titre" name="button_search" src="'.img_picto($langs->trans("Search"),'search.png','','',1).'"  value="'.dol_escape_htmltag($langs->trans("Search")).'" title="'.dol_escape_htmltag($langs->trans("Search")).'">';
        print '</td>';

		//ALL/NONE
		print '<td align="center" class="liste_titre">';
		if ($conf->use_javascript_ajax) print '<a href="#" id="checkall">'.$langs->trans("All").'</a> / <a href="#" id="checknone">'.$langs->trans("None").'</a>';
		print '</td>';

		print '</tr>';
		print '</form>';

		print '<form name="orders2invoice" action="orderstoinvoice.php" method="GET">';
		$var=true;
		$generic_commande = new Commande($db);

		while ($i < $num)
		{
			$objp = $db->fetch_object($resql);

			print '<tr class="oddeven">';
			print '<td class="nowrap">';

			$generic_commande->id=$objp->rowid;
			$generic_commande->ref=$objp->ref;
			$generic_commande->socid=$objp->socid;
			$generic_commande->fetch_thirdparty();
			$generic_commande->statut = $objp->fk_statut;
			$generic_commande->date_commande = $db->jdate($objp->date_commande);
			$generic_commande->date_livraison = $db->jdate($objp->date_livraison);

			print '<table class="nobordernopadding"><tr class="nocellnopadd">';
			print '<td class="nobordernopadding nowrap">';
			print $generic_commande->getNomUrl(1,0);
			print '</td>';
			print '<td width="20" class="nobordernopadding nowrap">';
			if ($generic_commande->hasDelay()) {
				print img_picto($langs->trans("Late"),"warning");
			}
			print '</td>';

			print '<td width="16" align="right" class="nobordernopadding hideonsmartphone">';
			$filename=dol_sanitizeFileName($objp->ref);
			$filedir=$conf->commande->dir_output . '/' . dol_sanitizeFileName($objp->ref);
			$urlsource=$_SERVER['PHP_SELF'].'?id='.$objp->rowid;
			print $formfile->getDocumentsLink($generic_commande->element, $filename, $filedir);
			print '</td></tr></table>';
			print '</td>';
			print '<td class="nobordernopadding nowrap">';
			print $generic_commande->thirdparty->getNomUrl(1);
			print '</td>';
			
			print '<td>'.$objp->ref_client.'</td>';

			// Order date
			print '<td align="center" class="nowrap">';
			print dol_print_date($db->jdate($objp->date_commande),'day');
			print '</td>';

			//Delivery date
			print '<td align="center" class="nowrap">';
			print dol_print_date($db->jdate($objp->date_livraison),'day');
			print '</td>';

			// Statut
			print '<td align="right" class="nowrap">'.$generic_commande->LibStatut($objp->fk_statut,$objp->facturee,5).'</td>';

			// Checkbox
			print '<td align="center">';
			print '<input class="flat checkformerge" type="checkbox" name="orders_to_invoice['.$objp->fk_soc.']['.$objp->rowid.']" value="1">';
			print '</td>' ;

			print '</tr>';

			$total = $total + $objp->price;
			$subtotal = $subtotal + $objp->price;
			$i++;
		}
		print '</table>';

		/*
		 * Boutons actions
		*/
		print '<br><div class="center"><input type="checkbox" '.(empty($conf->global->INVOICE_CLOSE_ORDERS_OFF_BY_DEFAULT_FORMASSINVOICE)?' checked="checked"':'').' name="autocloseorders"> '.$langs->trans("CloseProcessedOrdersAutomatically");
		print '<div align="right">';
		print '<input type="hidden" name="socid" value="'.$socid.'">';
		print '<input type="hidden" name="action" value="create">';
		print '<input type="hidden" name="origin" value="commande"><br>';
		//print '<a class="butAction" href="index.php">'.$langs->trans("GoBack").'</a>';
		print '<input type="submit" class="butAction" value="'.$langs->trans("GenerateBill").'">';
		print '</div>';
		print '</div>';
		print '</form>';
		$db->free($resql);
	}
	else
	{
		dol_print_error($db);
	}

}

llxFooter();
$db->close();
