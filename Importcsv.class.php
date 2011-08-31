<?php
include_once(realpath(dirname(__FILE__)) . "/../../../classes/PluginsClassiques.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Accessoire.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Produit.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Produitdesc.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Rubrique.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Rubriquedesc.class.php");
	
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
            'ignoreFirstRow' => false, // ignorer la premiere ligne du fichier
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

                case 2 : // Étape 2 : choix des colonnes et mise à jour BDD
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
                    $doublons = $this->getColsDoublons($_POST['col']);
                    if(count($doublons) > 0) {
                        $res['etape'] = 2;
                        $this->_error = 'Des colonnes sont en doublon : ' . implode(',', $doublons);
                        break;
                    }
                    $res['etape'] = 3;
                    
                    // Ignorer la première ligne ?
                    if(!empty($_REQUEST['importcsv_ignoreFirstRow'])) {
                        $res['ignoreFirstRow'] = true;
                    }
                    
                    $tabRefAccessoires = array(); // tableau qui contiendra les ref des produits associés (accessoires)

                    $ligne=0;
                    $csv = new Csv(self::$uploadFolder . $res['file']);
                    while($csvdata = $csv->getLineData()) {
                        $ligne++;
                        // on ignore la première ligne ?
                        if($res['ignoreFirstRow'] && $ligne==1) continue;

                        $params = array();
                        $params['lang'] = 1;
                        $params['rubrique_id'] = 0; 
                        $params['produit_id'] = 0;
                        $params['produit_ref'] = '';
                        $params['produit_titre'] = '';

                        // Colonne "titre de la rubrique de niveau 0" ?
                        $col = current(array_keys($_POST['col'], 'rubrique_titre0'));
                        if($col !== false) {
                            $rub = $this->_searchRubriqueByTitre($csvdata[$col], $params['lang']);
                            if(!$rub) { // la rubrique n'existe pas => création
                                $rub = $this->_createRub(0, $csvdata[$col], $params['lang']);
                                if(!$rub) {
                                    $this->_error .= '<br/>Impossible de créer la rubique ' . $csvdata[$col] . ' à la ligne' . $ligne;
                                    continue;
                                }
                            }
                            $params['rubrique_id'] = $rub;

                            // Colonne "titre de la rubrique de niveau 1" ?
                            $col = current(array_keys($_POST['col'], 'rubrique_titre1'));
                            if($col !== false) {
                               $rub = $this->_searchRubriqueByTitre($csvdata[$col], $params['lang']);
                               if(!$rub) { // la rubrique n'existe pas => création
                                   $rub = $this->_createRub($params['rubrique_id'], $csvdata[$col], $params['lang']);
                                   if(!$rub) {
                                       $this->_error .= '<br/>Impossible de créer la rubique ' . $csvdata[$col] . ' à la ligne' . $ligne;
                                       continue;
                                   }
                               }
                               $params['rubrique_id'] = $rub;
                            }
                            unset($rub);
                        }

                        // Colonne "produit_ref" ?
                        $col = current(array_keys($_POST['col'], 'produit_ref'));
                        if($col !== false) {
                            // Recherche ou création du produit avec la ref
                            $prodId = $this->_searchProdByRef($csvdata[$col]);
                            if(!$prodId) { // le produit n'xiste pas => création
                                $prodId = $this->_createProd($csvdata[$col], $params['rubrique_id']);
                                if(!$prodId) {
                                    $this->_error .= '<br/>Impossible de créer le produit ref ' . $csvdata[$col] . ' à la ligne' . $ligne;
                                    continue;
                                }
                            }
                            $params['produit_id'] = $prodId;
                            $params['produit_ref'] = $csvdata[$col];
                            unset($prodId);
                            
                            // Le produit change de rubrique ?
                            if(!empty($params['rubrique_id']) && $params['rubrique_id'] != $row->rubrique) {
                                $this->_updateProdRubId($params['produit_id'], $params['rubrique_id']);
                            }
                        }

                        // Colonne "produit_titre" ?
                        $col = current(array_keys($_POST['col'], 'produit_titre'));
                        if($col !== false) {
                            // Dispose t-on déjà d'un identifiant produit 
                            // ou doit-on utiliser le titre comme moyen d'identification ?
                            if(!empty($params['produit_id'])) {
                                // Le produit existe déjà => mise à jour éventuelle du titre
                                $this->_updateProdTitre($params['produit_id'], $csvdata[$col], $lang);
                            } else {
                                // création d'un produit avec ce titre
                                $prodInsert = $this->_createProdWithTitre($params['rubrique_id'], $csvdata[$col], $lang);
                                if(!$prodId) {
                                    $this->_error .= '<br/>Impossible de créer le produit titre ' . $csvdata[$col] . ' à la ligne' . $ligne;
                                    continue;
                                }
                                list($params['produit_id'], $params['produit_ref']) = $prodInsert;
                            }
                        }
                        
                        // Colonne "Ref de produits associés" ?
                        $col = current(array_keys($_POST['col'], 'accessoire_ref'));
                        if($col !== false) {
                            $tabRefAccessoires[$params['produit_id']] = array_unique(explode(',', (str_replace(' ', '', $csvdata[$col])))); 
                        }
                    } // fin du parcourt des lignes du fichier

                    // Colonne "Ref de produits associés" (accessoires)?
                    // Les accessoires sont enregistrés dans le tableau $tabRefAccessoires
                    $col = current(array_keys($_POST['col'], 'accessoire_ref'));
                    if($col !== false) {
                        foreach($tabRefAccessoires as $key => $refAccessoires) {
                            $this->_insertAccessoiresByRef($key, $refAccessoires);
                        }
                    }
                    break;
            } // find du switch
        }

        if(!file_exists(realpath(dirname(__FILE__)) . '/forms/etape' . $res['etape'] . '.php')) $res['etape'] = 1;

        return $res;
    }

    private function _insertAccessoiresById($idProduit, $idAccessoires, $verif=true) {
        if($verif) {
            
        }
        
        // calcul du classement
        $req_classement = $this->query(
        	'SELECT IFNULL(MAX(classement),-1)+1 AS classement 
        	FROM ' . Accessoire::TABLE . '
        	WHERE produit=' . $idProduit);
        $row = mysql_fetch_assoc($req_classement);
        $classement = $row['classement'];
        
        $req_insert = '
        	INSERT INTO ' . Accessoire::TABLE . ' (
    			`produit`, `accessoire`, `classement`
    		) VALUES ';
        foreach($idAccessoires as $accessoire) {
            $req_insert.= '('.$idProduit.', ' . $accessoire. ', ' . $classement . '),';
            $classement++;
        }
        $req_insert = substr($req_insert, 0, -1); // on vire la dernière virgule
        $this->query($req_insert);
    }
    
    /**
     * Insertion de produit accessoires à partir de refs
     * @param int $idProduit
     * @param array $refAccessoires
     */
    private function _insertAccessoiresByRef($idProduit, $refAccessoires) {
        if(!preg_match('/^[0-9]{1,}$/', $idProduit) || empty($idProduit)) return false;
        if(!is_array($refAccessoires) || empty($refAccessoires)) return false;

        $tabIdAccesssoires = array(); // tableau des ID produits à insérer

        // On récupère les ID des produits que l'on doit insérer comme
        // accesssoire, mais qui ne sont pas encore accessoire (AND accessoire.id IS NULL)
        $req = $this->query('
        	SELECT 
        		produit.id, 
        		accessoire.id AS accessoire
        	FROM ' . Produit::TABLE . ' 
        		LEFT OUTER JOIN ' . Accessoire::TABLE . ' AS accessoire ON(
        			produit.id=accessoire.accessoire
        			AND accessoire.produit=' . $idProduit . '
        		)
        	WHERE 
        		produit.ref IN(\'' . implode("','", $refAccessoires) . '\')
        		AND accessoire.id IS NULL');
        //var_dump($req);
        if(mysql_num_rows($req) < 1) return false;
        while($row = mysql_fetch_object($req)) {
            $tabIdAccesssoires[] = $row->id; 
        }
        if(empty($tabIdAccesssoires)) return false;
        return $this->_insertAccessoiresById($idProduit, $tabIdAccesssoires, false);
    }

    /**
     * Mise à jour de la rubrique d'appartenance d'un produit
     * @param int $idProduit
     * @param int $idRubrique
     */
    private function _updateProdRubId($idProduit, $idRubrique) {
        if(!preg_match('/^[0-9]{1,}$/', $idProduit) || empty($idProduit)) return false;
        if(!preg_match('/^[0-9]{1,}$/', $idRubrique) || empty($idRubrique)) return false;
        $this->query('UPDATE ' . Produit::TABLE . ' SET rubrique=' . $idRubrique . ' WHERE id=' . $idProduit);
        return this;
    }
    
    /**
     * Recherche d'un produit par référence
     * @param string $ref
     * @return int, false
     */
    private function _searchProdByRef($ref) {
        $req = $this->query('
        	SELECT id, rubrique 
        	FROM ' . Produit::TABLE . '
        	WHERE ref="' . mysql_real_escape_string($ref) . '"');
        if(mysql_num_rows($req) != 1) return false;
        $row = mysql_fetch_object($req);
        return $row->id;
    }
    
    /**
     * Mise à jour ou insertion du titre d'un produits
     * @param int $idProduit
     * @param string $titre
     * @param int $lang
     */
    private function _updateProdTitre($idProduit=0, $titre='', $lang=1) {
        if(!preg_match('/^[0-9]{1,}$/', $lang) || $lang < 1) $lang=1;
        $req = $this->query('
        	SELECT id, titre
        	FROM ' . Produitdesc::TABLE . '
        	WHERE 
        		produit="' . $idProduit . '"
                AND lang=' . $lang);
         if(mysql_num_rows($req) == 1) {
             // le produit a déjà un titre, mais est-ce le même ?
             $row = mysql_fetch_object($req);
             if($row->titre != $titre) { // on met à jour le titre
                 $req = $this->query('
                 	UPDATE ' . Produitdesc::TABLE . ' 
                 	SET titre=' . mysql_real_escape_string($titre) . '
                 	WHERE id=' . $row->id);
             }
         } else { // Le produit n'a pas encore de titre => INSERT
             $req = $this->query('
             	INSERT INTO ' . Produitdesc::TABLE . ' (
             		`produit`, `lang`, `titre`
             	) VALUES ( ' .
                    $idProduit . ', ' . 
                    $lang . ', 
                    "' . mysql_real_escape_string($titre) . '"
             	)');
         }
    }
    
    /**
	 * Recherche une rubrique par son titre
     * @param string $titre
     * @param int $lang
     * @return int, false
     */
    private function _searchRubriqueByTitre($titre, $lang=1) {
        if(!preg_match('/^[0-9]{1,}$/', $lang) || $lang < 1) $lang=1;
        $req = $this->query('
        	SELECT rubrique
        	FROM ' . Rubriquedesc::TABLE . ' 
        	WHERE titre="' . mysql_real_escape_string($titre) . '"
				  AND lang= ' . $lang
        );
        if(mysql_num_rows($req) != 1) return false;
        $row = mysql_fetch_object($req);
        return $row->rubrique;
    }
    
    /**
     * Création d'un produit à partir d'une ref
     * @param string $ref
     * @param int $idRubrique
     */
    private function _createProd($ref, $idRubrique) {
        if(!preg_match('/^[0-9]{1,}$/', $idRubrique) || empty($idRubrique)) return false;
        $req = $this->query('
			INSERT INTO ' . Produit::TABLE . '(
        		`ref`, `datemodif`, `rubrique`
        	) VALUES(
        		"' . mysql_real_escape_string($ref) . '", 
        		NOW(), ' . 
        		$idRubrique . '
        	)');
        return mysql_insert_id();
    }
    
    /**
     * Création d'un produit à partir d'un titre. 
     * La réf du produit est générée à partir du titre. 
     * @param int $idRubrique
     * @param string $titre
     * @param int $lang
     */
    private function _createProdWithTitre($idRubrique, $titre, $lang=1) {
        if(!preg_match('/^[0-9]{1,}$/', $idRubrique) || empty($idRubrique)) return false;
        if(!preg_match('/^[0-9]{1,}$/', $lang) || $lang < 1) $lang=1;
        
        $ref = rawurlencode(str_replace(array(' ', '/', '+', '.', ',', ';', "'", '\n', '"'), '', $titre));
        if(empty($ref)) $ref = microtime();
        
        // Création du produit en table Produit
        $idProd = $this->_createProd($ref, $idRubrique);
        // Insertion du titre en table Produitdesc
        $this->_updateProdTitre($idProd, $titre, $lang);

        return array($idProd, $ref);
    }
    
    /**
     * Création d'une rubrique
     * @param int $parent
     * @param string $titre
     * @param int $lang
     */
    private function _createRub($parent=0, $titre='', $lang=1) {
        if(!preg_match('/^[0-9]{1,}$/', $lang) || $lang < 1) $lang=1;
        if(!preg_match('/^[0-9]{1,}$/', $parent)) $parent=0;

        $req = $this->query('
        	INSERT INTO ' . Rubrique::TABLE . '(
        		`parent`, `lien`, `ligne`, `classement`
        	) VALUES( '. 
        		$parent . ', 
        		"", 
        		1, 
        		(	SELECT IFNULL(MAX(classement),-1)+1 AS classement 
        			FROM ' . Rubrique::TABLE. ' AS rub2 
        			WHERE parent=' . $parent . '
        		)
        	)');
        $idRubrique = mysql_insert_id();

        // création en table rubriquedesc
        $req = $this->query('
        	INSERT INTO ' . Rubriquedesc::TABLE . '(
        		`rubrique`, `lang`, `titre`
        	) VALUES(' . 
                $idRubrique . ', ' .
        		$lang . ', 
        		"' . mysql_real_escape_string($titre) . '"
        	)');
        return $idRubrique;
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
                'accessoire_ref' => 'Réf de produit(s) associés '
            ),
            'rubrique' => array(
                'rubrique_id' => 'ID rubrique',
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
    public function getColsDoublons($array){
        $res = array();
        if (!is_array($array)) return $res; 

        $array_unique = array_unique($array); 
    
        if (count($array) - count($array_unique)){ 
            for ($i=0; $i<count($array); $i++) {
                if (!array_key_exists($i, $array_unique)) {
                    if(!empty($array[$i])) {
                        $res[] = $array[$i];
                    }
                }
            } 
        }
        return $res;
    } 
}