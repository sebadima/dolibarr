<?php
/* Copyright (C) 2003      Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2016 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2013 Regis Houssin        <regis.houssin@capnetworks.com>
 * Copyright (C) 2015	   Alexandre Spangaro   <aspangaro.dolibarr@gmail.com>
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
 *	    \file       htdocs/compta/tva/card.php
 *      \ingroup    tax
 *		\brief      Page of VAT payments
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/tva.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

$langs->load("compta");
$langs->load("banks");
$langs->load("bills");

$id=GETPOST("id",'int');
$action=GETPOST("action","alpha");
$refund=GETPOST("refund","int");
if (empty($refund)) $refund=0;

// Security check
$socid = isset($_GET["socid"])?$_GET["socid"]:'';
if ($user->societe_id) $socid=$user->societe_id;
$result = restrictedArea($user, 'tax', '', '', 'charges');

$object = new Tva($db);

// Initialize technical object to manage hooks of thirdparties. Note that conf->hooks_modules contains array array
$hookmanager->initHooks(array('taxvatcard','globalcard'));


/**
 * Actions
 */

if ($_POST["cancel"] == $langs->trans("Cancel") && ! $id)
{
	header("Location: reglement.php");
	exit;
}

if ($action == 'setdatev' && $user->rights->tax->charges->creer)
{
    $object->fetch($id);
    $object->datev=dol_mktime(12,0,0,$_POST['datevmonth'],$_POST['datevday'],$_POST['datevyear']);
    $result=$object->update($user);
    if ($result < 0) dol_print_error($db,$object->error);
    
    $action='';
}

if ($action == 'add' && $_POST["cancel"] <> $langs->trans("Cancel"))
{
    $error=0;

	$datev=dol_mktime(12,0,0, $_POST["datevmonth"], $_POST["datevday"], $_POST["datevyear"]);
    $datep=dol_mktime(12,0,0, $_POST["datepmonth"], $_POST["datepday"], $_POST["datepyear"]);

    $object->accountid=GETPOST("accountid");
    $object->type_payment=GETPOST("type_payment");
	$object->num_payment=GETPOST("num_payment");
    $object->datev=$datev;
    $object->datep=$datep;
	
	$amount = price2num(GETPOST("amount"));
	if ($refund == 1) {
		$amount= -$amount;
	}
    $object->amount= $amount;
	$object->label=GETPOST("label");
	$object->note=GETPOST("note");
	
	if (empty($object->datev))
	{
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("DateValue")), null, 'errors');
		$error++;
	}
	if (empty($object->datep))
	{
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("DatePayment")), null, 'errors');
		$error++;
	}
	if (empty($object->type_payment) || $object->type_payment < 0)
	{
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("PaymentMode")), null, 'errors');
		$error++;
	}
	if (empty($object->amount))
	{
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Amount")), null, 'errors');
		$error++;
	}

	if (! $error)
	{
		$db->begin();

    	$ret=$object->addPayment($user);
		if ($ret > 0)
		{
			$db->commit();
			header("Location: reglement.php");
			exit;
		}
		else
		{
			$db->rollback();
			setEventMessages($object->error, $object->errors, 'errors');
			$action="create";
		}
	}

	$action='create';
}

if ($action == 'delete')
{
    $result=$object->fetch($id);

	if ($object->rappro == 0)
	{
	    $db->begin();

	    $ret=$object->delete($user);
	    if ($ret > 0)
	    {
			if ($object->fk_bank)
			{
				$accountline=new AccountLine($db);
				$result=$accountline->fetch($object->fk_bank);
				if ($result > 0) $result=$accountline->delete($user);	// $result may be 0 if not found (when bank entry was deleted manually and fk_bank point to nothing)
			}

			if ($result >= 0)
			{
				$db->commit();
				header("Location: ".DOL_URL_ROOT.'/compta/tva/reglement.php');
				exit;
			}
			else
			{
				$object->error=$accountline->error;
				$db->rollback();
				setEventMessages($object->error, $object->errors, 'errors');
			}
	    }
	    else
	    {
	        $db->rollback();
	        setEventMessages($object->error, $object->errors, 'errors');
	    }
	}
	else
	{
        setEventMessages('Error try do delete a line linked to a conciliated bank transaction', null, 'errors');
	}
}


/*
 *	View
 */

llxHeader();

$form = new Form($db);

if ($id)
{
	$result = $object->fetch($id);
	if ($result <= 0)
	{
		dol_print_error($db);
		exit;
	}
}

// Formulaire saisie tva
if ($action == 'create')
{
	print load_fiche_titre($langs->trans("VAT") . ' - ' . $langs->trans("New"));

	if (! empty($conf->use_javascript_ajax))
    {
        print "\n".'<script type="text/javascript" language="javascript">';
        print '$(document).ready(function () {
                $("#radiopayment").click(function() {
                    $("#label").val($(this).data("label"));
                    
                });
                $("#radiorefund").click(function() {
                    $("#label").val($(this).data("label"));
                    
                });
        });';
		print '</script>'."\n";
	}

    print '<form name="add" action="'.$_SERVER["PHP_SELF"].'" name="formvat" method="post">';
    print '<input type="hidden" name="token" value="'.$_SESSION['newtoken'].'">';
    print '<input type="hidden" name="action" value="add">';

    print '<div id="selectmethod">';
    print '<div class="hideonsmartphone float">';
    print $langs->trans("Type").':&nbsp;&nbsp;&nbsp;';
    print '</div>';
    print '<label for="radiopayment">';
    print '<input type="radio" id="radiopayment" data-label="'.$langs->trans('VATPayment').'" class="flat" name="refund" value="0"'.($refund?'':' checked="checked"').'>';
    print '&nbsp;';
    print $langs->trans("Payment");
    print '</label>';
    print '&nbsp;&nbsp;&nbsp;';
    print '<label for="radiorefund">';
    print '<input type="radio" id="radiorefund" data-label="'.$langs->trans('VATRefund').'" class="flat" name="refund" value="1"'.($refund?' checked="checked"':'').'>';
    print '&nbsp;';
    print $langs->trans("Refund");
    print '</label>';
    print '</div>';
    print "<br>\n";
	
    dol_fiche_head();

    print '<table class="border" width="100%">';

    print "<tr>";
    print '<td class="fieldrequired">'.$langs->trans("DatePayment").'</td><td>';
    print $form->select_date($datep,"datep",'','','','add',1,1);
    print '</td></tr>';

    print '<tr><td class="fieldrequired">'.$langs->trans("DateValue").'</td><td>';
    print $form->select_date($datev,"datev",'','','','add',1,1);
    print '</td></tr>';

	// Label
	if ($refund == 1) {
		$label = $langs->trans("VATRefund");
	} else {
		$label = $langs->trans("VATPayment");
	}
	print '<tr><td class="fieldrequired">'.$langs->trans("Label").'</td><td><input name="label" id="label" size="40" value="'.($_POST["label"]?$_POST["label"]:$label).'"></td></tr>';

	// Amount
	print '<tr><td class="fieldrequired">'.$langs->trans("Amount").'</td><td><input name="amount" size="10" value="'.$_POST["amount"].'"></td></tr>';

    if (! empty($conf->banque->enabled))
    {
		print '<tr><td class="fieldrequired">'.$langs->trans("Account").'</td><td>';
        $form->select_comptes($_POST["accountid"],"accountid",0,"courant=1",1);  // Affiche liste des comptes courant
        print '</td></tr>';
    }

    // Type payment
	print '<tr><td class="fieldrequired">'.$langs->trans("PaymentMode").'</td><td>';
	$form->select_types_paiements(GETPOST("type_payment"), "type_payment");
	print "</td>\n";
	print "</tr>";
	
	// Number
	print '<tr><td>'.$langs->trans('Numero');
	print ' <em>('.$langs->trans("ChequeOrTransferNumber").')</em>';
	print '<td><input name="num_payment" type="text" value="'.GETPOST("num_payment").'"></td></tr>'."\n";
	
    // Other attributes
    $parameters=array('colspan' => ' colspan="1"');
    $reshook=$hookmanager->executeHooks('formObjectOptions',$parameters,$object,$action);    // Note that $action and $object may have been modified by hook

    print '</table>';

    dol_fiche_end();

	print '<div class="center">';
	print '<input type="submit" class="button" value="'.$langs->trans("Save").'">';
	print '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
    print '<input type="submit" class="button" name="cancel" value="'.$langs->trans("Cancel").'">';
	print '</div>';

    print '</form>';
}


/* ************************************************************************** */
/*                                                                            */
/* Barre d'action                                                             */
/*                                                                            */
/* ************************************************************************** */

if ($id)
{
	$h = 0;
	$head[$h][0] = DOL_URL_ROOT.'/compta/tva/card.php?id='.$object->id;
	$head[$h][1] = $langs->trans('Card');
	$head[$h][2] = 'card';
	$h++;

	dol_fiche_head($head, 'card', $langs->trans("VATPayment"), 0, 'payment');


	print '<table class="border" width="100%">';

	print "<tr>";
	print '<td width="25%">'.$langs->trans("Ref").'</td><td colspan="3">';
	print $object->ref;
	print '</td></tr>';

	// Label
	print '<tr><td>'.$langs->trans("Label").'</td><td>'.$object->label.'</td></tr>';

	print "<tr>";
	print '<td>'.$langs->trans("DatePayment").'</td><td colspan="3">';
	print dol_print_date($object->datep,'day');
	print '</td></tr>';


	print '<tr><td>';
	print $form->editfieldkey("DateValue", 'datev', $object->datev, $object, $user->rights->tax->charges->creer, 'day');
	print '</td><td colspan="3">';
	print $form->editfieldval("DateValue", 'datev', $object->datev, $object, $user->rights->tax->charges->creer, 'day');
	//print dol_print_date($object->datev,'day');
	print '</td></tr>';

	print '<tr><td>'.$langs->trans("Amount").'</td><td colspan="3">'.price($object->amount).'</td></tr>';

	if (! empty($conf->banque->enabled))
	{
		if ($object->fk_account > 0)
		{
 		   	$bankline=new AccountLine($db);
    		$bankline->fetch($object->fk_bank);

	    	print '<tr>';
	    	print '<td>'.$langs->trans('BankTransactionLine').'</td>';
			print '<td colspan="3">';
			print $bankline->getNomUrl(1,0,'showall');
	    	print '</td>';
	    	print '</tr>';
		}
	}

        // Other attributes
        $parameters=array('colspan' => ' colspan="3"');
        $reshook=$hookmanager->executeHooks('formObjectOptions',$parameters,$object,$action);    // Note that $action and $object may have been modified by hook

	print '</table>';

	dol_fiche_end();

	
	/*
	* Boutons d'actions
	*/
	print "<div class=\"tabsAction\">\n";
	if ($object->rappro == 0)
	{
		if (! empty($user->rights->tax->charges->supprimer))
		{
			print '<a class="butActionDelete" href="card.php?id='.$object->id.'&action=delete">'.$langs->trans("Delete").'</a>';
		}
		else
		{
			print '<a class="butActionRefused" href="#" title="'.(dol_escape_htmltag($langs->trans("NotAllowed"))).'">'.$langs->trans("Delete").'</a>';
		}
	}
	else
	{
		print '<a class="butActionRefused" href="#" title="'.$langs->trans("LinkedToAConcialitedTransaction").'">'.$langs->trans("Delete").'</a>';
	}
	print "</div>";
}

llxFooter();
$db->close();
