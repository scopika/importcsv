<?php
require_once 'pre.php';
require_once 'auth.php';
?>
<div id="contenu_int" class="importcsv_container">
<?php
require_once realpath(dirname(__FILE__)) . '/Importcsv.class.php';
require_once realpath(dirname(__FILE__)) . '/Csv.class.php';
$importCsvObj = new Importcsv();

// Traitement des formulaires
$importCsvVars = $importCsvObj->verifForms();
//var_dump($importCsvVars);
?>
<p align="left">
	<a href="accueil.php" class="lien04">Accueil </a> <img src="gfx/suivant.gif" width="12" height="9" border="0" />
	<a href="module_liste.php" class="lien04">Liste des modules</a> <img src="gfx/suivant.gif" width="12" height="9" border="0" />
	<a href="module.php?nom=importcsv" class="lien04">Import CSV</a>
</p>

<div class="entete_liste">
	<div class="titre">IMPORT CSV</div>
</div>

<?php
// Message d'erreur ?
if($importCsvObj->getError() != '') {
    echo '<div class="importcsv_error">' . $importCsvObj->getError() . '</div>';
}

include_once realpath(dirname(__FILE__)) . '/forms/etape' . $importCsvVars['etape'] . '.php';
?>
</div> <!-- /#contenu_int -->