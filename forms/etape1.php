<?php
require_once 'pre.php';
require_once 'auth.php';
?>
<form action="module.php?nom=importcsv" method="post" enctype="multipart/form-data">
<input type="hidden" name="importcsv_etape" value="1"/>
<input type="file" 	 name="importcsv_file" />
<input type="submit" name="submit" value="Envoyer"/>
</form>