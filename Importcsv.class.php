<?php
include_once(realpath(dirname(__FILE__)) . "/../../../classes/PluginsClassiques.class.php");
	
class Importcsv extends PluginsClassiques{

    private $_error = null;
    public static $uploadFolder = null; // dossier d'upload des fichiers CSV
    
    function __construct($nom='') {
        self::$uploadFolder = realpath(dirname(__FILE__)) . '/upload/';
        parent::__construct($nom);
    }

    /**
     * Traitement des formulaires
     * @return array
     */
    public function verifForms() {
        $res = array(
            'etape' => 1,
        	'file' => null, // nom du fichier CSV
            'importsql_pagination' => 0 // on importe par paquets
        );

        if(!empty($_REQUEST['importcsv_etape']) && preg_match('/^[0-9]{1,}$/', $_REQUEST['importcsv_etape'])) {
             switch($_POST['importcsv_etape']) {
                case 1 :
                    // Etape 1 : import d'un fichier CSV
                    if(!empty($_FILES['importcsv_file']['tmp_name'])) {
                        preg_match("/([^\/]*).((csv))/i", $_FILES['importcsv_file']['name'], $decoupe);
                		$fich = eregfic($decoupe[1]);
                		$extension = $decoupe[2];
                		if($fich == "" || $extension == "") {
                			$this->_error = 'Fichier non conforme'; 
                			return false;
                		}
                		$csvFileName = time() . '.csv';
                		copy($_FILES['importcsv_file']['tmp_name'], self::$uploadFolder . $csvFileName);
                		$res['file'] = $csvFileName;
                		$res['etape'] = 2;
                    }
                    break;

                case 2 : // Étape 2 : choix des colonnes
                    // Fichier sur lequel on travaille
                    if(empty($_REQUEST['importcsv_file']) || !file_exists(self::$uploadFolder . $_REQUEST['importcsv_file']) || !preg_match('/^[0-9]{1,}.csv$/', $_REQUEST['importcsv_file'])) {
                        $res['etape'] = 1;
                        $this->_error = 'Aucun fichier transmis, ou fichier introuvable';
                        break;
                    }
                    $res['file'] = $_REQUEST['importcsv_file'];

                    // Au moins une colonne est renseignée ?
                    $emptyCols = true;
                    foreach((array) $_POST['col'] as $key => $col) {
                        if(!empty($col)) {
                            $emptyCols = false;
                            break;
                        }
                    }
                    if($emptyCols) { // Les colonnes sont vides, on retourne à l'étape 2
                        $res['etape'] = 2;
                        $this->_error = 'Aucune colonne n\'est renseignée';
                        break;
                    }

                    // On vérifie qu'il n'y ai pas de colonnes en doublon
                    $doublons = $this->getArrayDoublons($_POST['col']);
                    if(!count($doublons) > 0) {
                        $res['etape'] = 2;
                        $this->_error = 'Des colonnes sont en doublon : ' . implode(',', $doublons);
                        break;
                    }
                    $res['etape'] = 3;

                    // instances utiles
                    $produit = new Produit();
                    $produitdesc = new Produitdesc();
                    $rubrique = new Rubrique();
                    $rubriquedesc = new Rubriquedesc();

                    $csv = new Csv(self::$uploadFolder . $res['file']);
                    while($csvdata = $csv->getLineData()) {
                        $params = array();
                        $params['lang'] = 1;
                        $params['rubrique_id'] = 0; 
                        $params['produit_id'] = 0;
                        $params['produit_titre'] = '';

                        // Colonne "titre de la rubrique de niveau 0" ?
                        $col = current(array_keys($_POST['col'], 'rubrique_titre0'));
                        if($col !== false) {
                           $req = $this->query('
                            	SELECT rubrique
                            	FROM ' . $rubriquedesc->table . ' 
                            	WHERE titre="' . mysql_real_escape_string($csvdata[$col]) . '"
                            		AND lang= ' . $params['lang']);
                            if(mysql_num_rows($req) == 1) { // la rubrique existe déjà
                                $row = mysql_fetch_object($req);
                                $params['rubrique_id'] = $row->rubrique;
                            } else { // La rubrique n'existe pas encore! 

                                // création en table rubrique
                                $req = $this->query('
                                	INSERT INTO ' . $rubrique->table . '(
                                		`parent`, `lien`, `ligne`, `classement`
                                	) VALUES(
                                		0, 
                                		"", 
                                		1, 
                                		(SELECT IFNULL(MAX(classement),-1)+1 AS classement FROM ' . $rubrique->table. ' AS rub2 WHERE parent=0)
                                	)');
                                $params['rubrique_id'] = mysql_insert_id();

                                // création en table rubriquedesc
                                $req = $this->query('
                                	INSERT INTO ' . $rubriquedesc->table . '(
                                		`rubrique`, `lang`, `titre`
                                	) VALUES(' . 
                                        $params['rubrique_id'] . ', ' .
                                		$params['lang'] . ', 
                                		"' . mysql_real_escape_string($csvdata[$col]) . '"
                                	)');
                            }

                            // Colonne "titre de la rubrique de niveau 1" ?
                            $col = current(array_keys($_POST['col'], 'rubrique_titre1'));
                            if($col !== false) {
                               $req = $this->query('
                                	SELECT rubrique
                                	FROM ' . $rubriquedesc->table . ' 
                                	WHERE titre="' . mysql_real_escape_string($csvdata[$col]) . '"
                                		AND lang= '. $params['lang']);
                                if(mysql_num_rows($req) == 1) { // la rubrique existe déjà
                                    $row = mysql_fetch_object($req);
                                    $params['rubrique_id'] = $row->rubrique;
                                } else { // La rubrique n'existe pas encore! 
    
                                    // création en table rubrique
                                    $req = $this->query('
                                    	INSERT INTO ' . $rubrique->table . '(
                                    		`parent`, `lien`, `ligne`, `classement`
                                    	) VALUES(' .
                                    		$params['rubrique_id'] . ', 
                                    		"", 
                                    		1, 
                                    		(SELECT IFNULL(MAX(classement),-1)+1 AS classement FROM ' . $rubrique->table. ' AS rub2 WHERE parent=' . $params['rubrique_id'] . ')
                                    	)');
                                    $params['rubrique_id'] = mysql_insert_id();
    
                                    // création en table rubriquedesc
                                    $req = $this->query('
                                    	INSERT INTO ' . $rubriquedesc->table . '(
                                    		`rubrique`, `lang`, `titre`
                                    	) VALUES(' . 
                                            $params['rubrique_id'] . ', ' .
                                    		$params['lang'] . ', 
                                    		"' . mysql_real_escape_string($csvdata[$col]) . '"
                                    	)');
                                }
                            }
                        } // /col 'titre de la rubrique de niveau 0'

                        // Colonne "produit_ref" ?
                        $col = current(array_keys($_POST['col'], 'produit_ref'));
                        if($col !== false) {
                            $req = $this->query('
                            	SELECT id, rubrique 
                            	FROM ' . $produit->table . '
                            	WHERE ref="' . mysql_real_escape_string($csvdata[$col]) . '"');
                            if(mysql_num_rows($req) == 1) {
                                //le produit existe déjà
                                $row = mysql_fetch_object($req);
                                $params['produit_id'] = $row->id;
                                if(!empty($params['rubrique_id']) && $params['rubrique_id'] != $row->rubrique) {
                                    // @todo : Le produit change de rubrique
                                }
                            } else {
                                // création en table produit
                                $req = $this->query('
									INSERT INTO ' . $produit->table . '(
                                		`ref`, `datemodif`, `rubrique`
                                	) VALUES(
                                		"' . mysql_real_escape_string($csvdata[$col]) . '", 
                                		NOW(), ' . 
                                		$params['rubrique_id'] . '
                                	)');
                                $params['produit_id'] = mysql_insert_id();
                             }
                        }
                        
                        // Colonne "produit_titre" ?
                        $col = current(array_keys($_POST['col'], 'produit_titre'));
                        if($col !== false) {
                            // Dispose t-on déjà d'un identifiant produit ?
                            if(!empty($params['produit_id'])) {
                                 $req = $this->query('
                                	SELECT id, titre
                                	FROM ' . $produitdesc->table . '
                                	WHERE 
                                		produit="' . $params['produit_id'] . '"
                                        AND lang=' . $params['lang']);
                                 if(mysql_num_rows($req) == 1) {
                                     // le produit a déjà un titre, mais est-ce le même ?
                                     $row = mysql_fetch_object($req);
                                     if($row->titre != $csvdata[$col]) {
                                         $req = $this->query('
                                         	UPDATE ' . $produitdesc->table . ' 
                                         	SET titre=' . mysql_real_escape_string($csvdata[$col]) . '
                                         	WHERE id=' . $row->id);
                                     }
                                 } else {
                                     // Le produit n'a pas encore de titre
                                     $req = $this->query('
                                     	INSERT INTO ' . $produitdesc->table . ' (
                                     		`produit`, `lang`, `titre`
                                     	) VALUES ( ' .
                                            $params['produit_id'] . ', ' . 
                                            $params['lang'] . ', 
                                            "' . mysql_real_escape_string($csvdata[$col]) . '"
                                     	)');
                                 }
                            } else {
                                // @todo
                            }
                        }
                        
                    }
                    break;
            }
        }

        if(!file_exists(realpath(dirname(__FILE__)) . '/forms/etape' . $res['etape'] . '.php')) $res['etape'] = 1;

        return $res;
    }
    
    /**
     * Dresse une liste des champs proposés pour faire 
     * la correspondance avec les colonnes de données
     * @return array
     */
    public function getSuggestedFields() {
        $fields = array(
            'produit' => array(
                //'produit_id' => 'ID produit',
                'produit_ref' => 'Ref produit',
                'produit_titre' => 'Titre du produit',
                'accessoire_ref' => 'Produits associés',
            ),
            'rubrique' => array(
                //'rubrique_id' => 'ID rubrique',
                'rubrique_titre0' => 'Titre de la rubrique de niveau 0',
                'rubrique_titre1' => 'Titre de la rubrique de niveau 1',
            ),
            /*'lang' => array(
                'lang_id' => 'ID langue',
                'lang_description' => 'Nom de la langue'
            ),*/
            
        );
        return $fields;
    }

    /**
     * Renvoie le message d'erreur
     */
    public function getError() {
        return $this->_error;
    }
    
    /**
     * Retourne la liste des champs en doublons dans un tableau
     * @param array $array
     * @return array
     */
    public function getArrayDoublons($array){
        $res = array();
        if (!is_array($array)) return $res; 

        $array_unique = array_unique($array); 
    
        if (count($array) - count($array_unique)){ 
            for ($i=0; $i<count($array); $i++) {
                if (!array_key_exists($i, $array_unique)) 
                    $res[] = $array[$i];
            } 
        } 
        return $res;
    } 
}