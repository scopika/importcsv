<link href="../client/plugins/importcsv/css/importcsv.css" rel="stylesheet" type="text/css" />
<?php
if(!is_writeable(realpath(dirname(__FILE__)) . '/upload/')) {
    echo '<div class="importcsv_error">[Plugin importcsv] : Le dossier ' .realpath(dirname(__FILE__)) . '/upload/ doit être inscriptible</div>';
}