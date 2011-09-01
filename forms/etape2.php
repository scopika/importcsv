<?php
require_once 'pre.php';
require_once 'auth.php';

$suggestedFields = $importCsvObj->getSuggestedFields();
$csv = new Csv(ImportCsv::$uploadFolder . $importCsvVars['file']);
$totalCols = $csv->getNumCols();
$dataPreview = $csv->getPreview();
?>
<form action="module.php?nom=importcsv" method="post" enctype="multipart/form-data">
<input type="hidden" name="importcsv_etape" value="2"/>
<input type="hidden" name="importcsv_file" value="<?php echo $importCsvVars['file']; ?>"/>
<div id="csvimport_tableoverflow">
<table class="importcsv_preview" cellspacing="0">
<thead>
<tr>
<?php
for($i=0; $i<$totalCols; $i++) {
    ?>
    <th>
    <select name="col[<?php echo $i; ?>]">
    <option value=""></option>
    <?php
    foreach((array) $suggestedFields as $group => $fields) { ?>
       <optgroup label="<?php echo $group; ?>"></optgroup>
       <?php
       foreach((array) $fields as $key => $field) {
           $selected = (!empty($_POST['col'][$i]) && $_POST['col'][$i] == $key) ? 'selected="selected"' : '';
           ?>
           <option value="<?php echo $key; ?>" <?php echo $selected; ?>>
               <?php echo $field; ?>
           </option>
           <?php
       }
    }
    ?>
    </select>
    </th>
    <?php
}
?>
</tr>
</thead>
<tbody>
<?php
foreach((array) $dataPreview as $key => $datas) { ?>
<tr>
	<?php
	foreach((array) $datas as $key => $data) { ?>
		<td><?php echo $data; ?></td>
	    <?php
	}
	?>
</tr>
<?php
}
?>
</tbody>
</table>
</div>
<p>
	<label for="importcsv_ignoreFirstRow">Ignorer la première ligne</label>
	<input type="checkbox" name="importcsv_ignoreFirstRow" id="importcsv_ignoreFirstRow" />
</p>
<input type="submit" name="submit" value="Enregistrer">
</form>

<div class="entete_liste" style="margin-top:20px">
    <div class="titre">AIDE : LES DIFFERENTS TYPES  DE COLONNES</div>
</div>

<table id="importcsv_aide">
<thead>
<tr>
    <th>Type de colonne</th>
    <th>Type de données</th>
    <th>Description</th>
    <th>Exemples</th>
</tr>
</thead>
<tbody>
<tr>
    <td>Ref produit</td>
    <td>Texte</td>
    <td>
        <strong>Référence du produit.</strong>
        <br/>- Ce type de colonne peut servir d'identificateur pour produit.
        C'est à dire que sa valeur pourra être utilisée par d'autres colonnes
        ('URL produit', 'Titre produit', 'Caractéristiques produit', ...) pour déterminer sur quel produit travailler.
        <br/>- Si la référence n'existe pas, elle est créée.
    </td>
    <td class="csvimport_exemples">0545<br/>dhx2252</td>
</tr>
<tr>
    <td>Titre du produit</td>
    <td>Texte</td>
    <td>
        <strong>Titre du produit (par défaut en langue française)</strong>
        <br/>- Ce type de colonne peut servir d'identificateur pour produit.
        C'est à dire que sa valeur pourra être utilisée par d'autres colonnes
        (URL produit, Caractéristiques produit, ...) pour déterminer sur quel produit travailler.
        <br/>- Si une colonne 'ref produit' est spécifiée, le titre du produit sera mis à jour.
        <br/>- Sinon, un nouveau produit sera créé avec le titre spécifié.
    </td>
    <td class="csvimport_exemples">Lave-Vaisselle X453</td>
</tr>
<tr>
    <td>URL du produit</td>
    <td>Texte</td>
    <td><strong>URL réécrite : </strong><br/>- sans protocole (http)<br/>- sans nom d'hote (mon-site.com).
        <br/>Une colonne d'identification du produit ('Ref produit', 'Titre produit', ...) doit être utilisée.
    </td>
    <td class="csvimport_exemples">mon-produit.html<br/>rubrique/produit-pas-cher-super-promo</td>
</tr>
<tr>
    <td>Réf de produit(s) associés</td>
    <td>Comma Separated Values</td>
    <td><strong>Liste des identifiants des produits associés.</strong>
        <br/>Une colonne d'identification du produit ('Ref produit', 'Titre produit', ...) doit être utilisée.
    </td>
    <td class="csvimport_exemples">123<br/>24,87,9854,454,21</td>
</tr>
<tr>
    <td>Titre de la rubrique </td>
    <td>Texte</td>
    <td><strong>Titre de la rubrique, par défaut en langue française.</strong>
        <br/>- Ce type de colonne peut servir d'identificateur pour rubrique.
        C'est à dire que sa valeur pourra être utilisée par d'autres colonnes
        (URL produit, Caractéristiques produit, ...) pour déterminer sur quelle rubrique travailler.
        <br/>- Si une ou plusieurs colonnes concernent les produits ('Ref Produit', 'Titre du produit', ...), cette colonne sera utilisée
        pour déterminer la rubrique d'appartenance du produit.
        <br/>- Si le titre spécifié ne correspond à aucune rubrique, une nouvelle rubrique est créée (à la racine du site).
    </td>
    <td class="csvimport_exemples">Chaussettes<br/>Vélos</td>
</tr>
<tr>
    <td>URL de la rubrique</td>
    <td>Texte</td>
    <td><strong>URL réécrite : </strong><br/>- sans protocole (http)<br/>- sans nom d'hote (mon-site.com).
        <br/>Une colonne d'identification de la rubrique doit être utilisée.
    </td>
    <td class="csvimport_exemples">chaussettes.html<br/>sous-vêtements/chaussettes</td>
</tr>
<tr>
    <td>Titre de la sous-rubrique </td>
    <td>Texte</td>
    <td><strong>Titre de la sous-rubrique, par défaut en langue française.</strong>
        <br/>- Ce type de colonne peut servir d'identificateur pour rubrique.
        C'est à dire que sa valeur pourra être utilisée par d'autres colonnes
        (URL produit, Caractéristiques produit, ...) pour déterminer sur quelle rubrique travailler.
        <br/>- Si une ou plusieurs colonnes concernent les produits ('Ref Produit', 'Titre du produit', ...), cette colonne sera utilisée
        pour déterminer la rubrique d'appartenance du produit.
        <br/>- Si le titre spécifié ne correspond à aucune rubrique, une nouvelle rubrique est créée, sous la rubrique parente.
        <br/>- Une colonne d'identification de la rubrique parente ('Titre de la rubrique') doit être utilisée.
    </td>
    <td class="csvimport_exemples">Chaussettes<br/>Vélos</td>
</tr>
<tr>
    <td>ID valeurs de caractéristiques (caracdisp)</td>
    <td>Comma Separated Values</td>
    <td><strong>Ajout de valeurs de caractéristiques (caracdisps) sur un produit</strong>
        <br/>- Une colonne d'identification du produit doit être spécifiée.
        <br/>- L'ID de la caracdisp doit exister en base, il ne sera pas créé automatiquement.
        <br/>- Évite les doublons (si la caracdisp est déjà définie sur le produit)
        <br/>- La caractéristique est automatiquement activée sur la rubrique d'appartenance du produit.
    </td>
    <td class="csvimport_exemples">32<br/>21,54,789</td>
</tr>
</tbody>

</table>