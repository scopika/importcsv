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
<p>
	<label for="importcsv_ignoreFirstRow">Ignorer la première ligne</label>
	<input type="checkbox" name="importcsv_ignoreFirstRow" id="importcsv_ignoreFirstRow" />
</p>
<input type="submit" name="submit" value="Enregistrer">
</form>