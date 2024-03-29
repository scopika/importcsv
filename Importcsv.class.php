<?php
include_once(realpath(dirname(__FILE__)) . "/../../../classes/PluginsClassiques.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Accessoire.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Caracdisp.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Caracdispdesc.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Caracteristique.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Caracteristiquedesc.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Produit.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Produitdesc.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Rubcaracteristique.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Rubrique.class.php");
include_once(realpath(dirname(__FILE__)) . "/../../../classes/Rubriquedesc.class.php");

class Importcsv extends PluginsClassiques {

    private $_error = null;
    private $_rubriquesMaxDepth = 1; // profondeur maximale de rubriquage proposé (commence à 0)
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

                        // Colonnes titre et URL de la rubrique de niveau X ?
                        for($i=0; $i<=$this->_rubriquesMaxDepth; $i++) {
                            $col = current(array_keys($_POST['col'], 'rubrique' . $i .'_titre'));
                            if($col !== false) {
                                $rub = $this->_searchRubriqueByTitre($csvdata[$col], $params['lang']);
                                if(!$rub) { // la rubrique n'existe pas => création
                                    $rub = $this->_createRub($params['rubrique_id'], $csvdata[$col], $params['lang']);
                                    if(!$rub) {
                                        $this->_error .= '<br/>Impossible de créer la rubrique ' . $csvdata[$col] . ' à la ligne' . $ligne;
                                        continue;
                                    }
                                }
                                $params['rubrique_id'] = $rub;

                                // Colonne "URL de la rubrique de niveau X" ?
                                $col = current(array_keys($_POST['col'], 'rubrique' . $i . '_url'));
                                if($col !== false) {
                                    $this->_updateRubURL($params['rubrique_id'], $csvdata[$col]);
                                }
                            }
                        }

                        // Colonne "produit_ref" ?
                        $col = current(array_keys($_POST['col'], 'produit_ref'));
                        if($col !== false) {
                            // Recherche ou création du produit avec la ref
                            $prodId = $this->_searchProdByRef($csvdata[$col]);
                            if(!$prodId) { // le produit n'existe pas => création
                                $prodId = $this->_createProd($csvdata[$col], $params['rubrique_id']);
                                if(!$prodId) {
                                    $this->_error .= '<br/>Impossible de créer le produit ref ' . $csvdata[$col] . ' à la ligne' . $ligne . ' (aucune rubrique spécifiée ?)';
                                    continue;
                                }
                            } elseif(!empty($_POST['importcsv_ignoreIfProdExists'])) continue;
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

                        // Colonne "URL du produit"
                        $col = current(array_keys($_POST['col'], 'produit_url'));
                        if($col !== false) {
                            $this->_updateProdUrl($params['produit_id'], $csvdata[$col], $lang);
                        }

                        // Colonne "prix du produit"
                        $col = current(array_keys($_POST['col'], 'produit_prix'));
                        if($col !== false) {
                            $this->_updateProdPrix($params['produit_id'], $csvdata[$col], $lang);
                        }

                        // Colonne "en ligne / hors-ligne"
                        $col = current(array_keys($_POST['col'], 'produit_ligne'));
                        if($col !== false) {
                            $this->_updateProdLigne($params['produit_id'], $csvdata[$col], $lang);
                        }

                        // Colonne "Ref de produits associés" ?
                        $col = current(array_keys($_POST['col'], 'accessoire_ref'));
                        if($col !== false) {
                            $tabRefAccessoires[$params['produit_id']] = array_unique(explode(',', (str_replace(' ', '', $csvdata[$col]))));
                        }

                        // Colonnes "Id caracdisps"
                        if(!empty($params['produit_id'])) {
                            $req = $this->query('SELECT caracteristique.id  FROM ' . Caracteristique::TABLE . ' AS caracteristique');
                            while($row = mysql_fetch_object($req)) {
                                $col = current(array_keys($_POST['col'], 'caracteristique_' . $row->id . '_caradispId'));
                                if($col !== false) {
                                    // Tableau qui contiendra les ID caracdisp à insérer en base
                                    $caracdisps = array_unique(explode(',', (str_replace(' ', '', $csvdata[$col]))));
                                    foreach((array) $caracdisps as $key => $caracdisp) {
                                        if(!preg_match('/^[0-9]{1,}$/', $caracdisp) || empty($caracdisp)) unset($caracdisps[$key]);
                                    }
                                    if(empty($caracdisps) || count($caracdisps)==0) continue;

                                    // On enlève de $caracdisps les ID déjà présents en base.
                                    // On en profite pour relever les ID rubriques sur lesquels
                                    // les caractéristiques correspondantes aux caracdisp devront être activés
                                    $rubriquesCarac = array();
                                    $reqExistingCaracvals = $this->query('
                                        SELECT caracval.caracdisp, produit.rubrique
                                        FROM ' . Caracval::TABLE . ' AS caracval
                                            LEFT JOIN ' . Produit::TABLE . ' ON(caracval.produit=produit.id)
                                        WHERE caracval.produit=' . $params['produit_id'] . '
                                            AND caracteristique=' . $row->id . '
                                            AND caracdisp IN(' . implode(',', $caracdisps) . ')'
                                    );
                                    while($rowExistingCaracvals = mysql_fetch_object($reqExistingCaracvals)) {
                                        if(!empty($rowExistingCaracvals->rubrique)) $rubriquesCarac[] = $rowExistingCaracvals->rubrique;
                                        $keyInArray = array_search($rowExistingCaracvals->caracdisp, $caracdisps);
                                        if($keyInArray===false) continue;
                                        unset($caracdisps[$keyInArray]);
                                    }

                                    // On vérifie d'abord que les caractéristiques soient bien activées
                                    // sur les rubriques
                                    $rubriquesCarac = array_unique($rubriquesCarac);
                                    foreach((array) $rubriquesCarac as $key => $rubrique) {
                                        $req = $this->query('
                                            SELECT id FROM ' . Rubcaracteristique::TABLE . '
                                            WHERE rubrique=' . $rubrique . ' AND caracteristique=' . $row->id);
                                        if(mysql_num_rows($req) >= 1) {
                                            unset($rubriquesCarac[$key]);
                                        }
                                    }
                                    if(!empty($rubriquesCarac)) {
                                        $req_insert = 'INSERT INTO ' . Rubcaracteristique::TABLE. ' (
                                            `rubrique`, `caracteristique`
                                        ) VALUES ';
                                        foreach($rubriquesCarac as $rubrique) {
                                            $req_insert.= '(' .
                                                $rubrique . ',' .
                                                $row->id .'),';
                                        }
                                        $req_insert = substr($req_insert, 0, -1); // on vire la dernière virgule
                                        $this->query($req_insert);
                                    }

                                    // Insertion des ID caracdisps restants
                                    if(empty($caracdisps)) continue;
                                    $req_insert = 'INSERT INTO ' . Caracval::TABLE. ' (
                                        `produit`, `caracteristique`, `caracdisp`
                                    ) VALUES ';
                                    foreach($caracdisps as $caracdisp) {
                                        $req_insert.= '(' .
                                            $params['produit_id'] .', ' .
                                            $row->id . ', ' .
                                            $caracdisp . '),';
                                    }
                                    $req_insert = substr($req_insert, 0, -1); // on vire la dernière virgule
                                    $this->query($req_insert);
                                }
                            }
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
     * Mise à jour du statut d'un produit
     * @param int $idProduit
     * @param int $ligne
     */
    private function _updateProdLigne($idProduit=0, $ligne=0) {
        if(!preg_match('/^[0-9]{1,}$/', $idProduit)) return false;
        if(!preg_match('/^[0-1]{1}$/', $ligne)) $ligne=0;

        $req = $this->query('
            UPDATE ' . Produit::TABLE . '
            SET ligne=' . $ligne . '
            WHERE id=' . $idProduit);
        return $this;
    }

    /**
     * Mise à jour du prix d'un produit
     * @param int $idProduit
     * @param float $prix
     */
    private function _updateProdPrix($idProduit=0, $prix=0) {
        if(!preg_match('/^[0-9]{1,}$/', $idProduit)) return false;
        $req = $this->query('
            UPDATE ' . Produit::TABLE . '
            SET prix=' . number_format(str_replace(array(',', ' '), array('.', ''), $prix), 2, '.', '') . '
            WHERE id=' . $idProduit);
        return $this;
    }

    /**
     * Mise à jour ou insertion du titre d'un produits
     * @param int $idProduit
     * @param string $titre
     * @param int $lang
     */
    private function _updateProdTitre($idProduit=0, $titre, $lang=1) {
        $titre = mysql_real_escape_string($titre);
        if(!preg_match('/^[0-9]{1,}$/', $idProduit)) return false;
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
                 	SET titre="' . $titre . '"
                 	WHERE id=' . $row->id);
             }
         } else { // Le produit n'a pas encore de titre => INSERT
            $req = $this->query('
             	INSERT INTO ' . Produitdesc::TABLE . ' (
             		`produit`, `lang`, `titre`
             	) VALUES ( ' .
                    $idProduit . ', ' .
                    $lang . ',
                    "' . $titre . '"
             	)');

            // On va avoir besoin de l'ID rubrique pour créer l'URL rewritée
            $reqProdRub = $this->query('SELECT rubrique FROM ' . Produit::TABLE . ' WHERE id=' . $idProduit);
            $rowProdRub = mysql_fetch_object($reqProdRub);

            //Attribution d'une URL réécrite
            $reecriture = new Reecriture();
            $reecriture->fond = "produit";
            $reecriture->url = $idProduit . '-' . rawurlencode(strtolower(str_replace(' ', '-', $titre)));
            $reecriture->param = "&id_produit=" . $idProduit . "&id_rubrique=" . $rowProdRub->rubrique;
            $reecriture->lang = $lang;
            $reecriture->actif = 1;
            $reecriture->add();
         }
    }

    private function _updateProdUrl($idProduit, $url, $lang=1) {
        if(!preg_match('/^[0-9]{1,}$/', $idProduit)) return false;
        if(empty($url)) return false;
        if(!preg_match('/^[0-9]{1,}$/', $lang) || $lang < 1) $lang=1;
        $url = $this->filterUrl($url);

        $req = $this->query('SELECT rubrique FROM ' . Produit::TABLE . ' WHERE id=' . $idProduit);
        if(mysql_num_rows($req) != 1) return false;
        $row = mysql_fetch_object($req);
        $idRubrique = $row->rubrique;

        $req = $this->query('
            SELECT id, url
            FROM ' . Reecriture::TABLE . '
            WHERE
                fond="produit"
                AND param="&id_produit=' . $idProduit . '&id_rubrique=' . $idRubrique . '"
                AND lang=' . $lang . '
            LIMIT 0,1');
        if(mysql_num_rows($req) == 1) {
            //une url réécrite existe déjà, on la remplace si différente
            $row = mysql_fetch_object($req);
            if($row->url != $url) {
                $this->query('UPDATE ' . Reecriture::TABLE . ' SET url="' . $url .'" WHERE id=' . $row->id);
            }
        } else {
            $reecriture = new Reecriture();
            $reecriture->fond = 'produit';
            $reecriture->url = $url;
            $reecriture->param = '&id_produit='. $idProduit . '&id_rubrique=' . $idRubrique;
            $reecriture->lang = $lang;
            $reecriture->actif = 1;
            $reecriture->add();
        }
        return $this;
    }

    private function _updateRubURL($idRubrique, $url, $lang=1) {
        if(!preg_match('/^[0-9]{1,}$/', $idRubrique)) return false;
        if(empty($url)) return false;
        if(!preg_match('/^[0-9]{1,}$/', $lang) || $lang < 1) $lang=1;
        $url = $this->filterUrl($url);

        $req = $this->query('
            SELECT id, url
            FROM ' . Reecriture::TABLE . '
            WHERE
                fond="rubrique"
                AND param="&id_rubrique=' . $idRubrique . '"
                AND lang=' . $lang . '
            LIMIT 0,1');
        if(mysql_num_rows($req) == 1) {
            //une url réécrite existe déjà, on la remplace si différente
            $row = mysql_fetch_object($req);
            if($row->url != $url) {
                $this->query('UPDATE ' . Reecriture::TABLE . ' SET url="' . $url .'" WHERE id=' . $row->id);
            }
        } else {
            $reecriture = new Reecriture();
            $reecriture->fond = "rubrique";
            $reecriture->url = $url;
            $reecriture->param = "&id_rubrique=" . $idRubrique;
            $reecriture->lang = $lang;
            $reecriture->actif = 1;
            $reecriture->add();
        }
        return $this;
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
    private function _createRub($parent=0, $titre, $lang=1) {
        if(!preg_match('/^[0-9]{1,}$/', $lang) || $lang < 1) $lang=1;
        if(!preg_match('/^[0-9]{1,}$/', $parent)) $parent=0;
        $titre = mysql_real_escape_string($titre);

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
        		"' . $titre . '"
        	)');

        // Attribution d'une URL à la nouvelle rubrique
        $url = $idRubrique . "-" . rawurlencode(strtolower(str_replace(' ', '-', $titre))) . ".html";
        $reecriture = new Reecriture();
        $reecriture->fond = "rubrique";
        $reecriture->url = $url;
        $reecriture->param = "&id_rubrique=" . $idRubrique;
        $reecriture->lang = $lang;
        $reecriture->actif = 1;
        $reecriture->add();

        return $idRubrique;
    }

    public function filterUrl($url) {
        // suppression des accents
        $url = htmlentities($url, ENT_NOQUOTES, 'utf-8');
        $url = preg_replace('#&([A-za-z])(?:acute|cedil|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $url);
        $url = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $url); // pour les ligatures e.g. '&oelig;'
        $url = preg_replace('#&[^;]+;#', '', $url); // supprime les autres caractères

        return rawurlencode(preg_replace('/[^a-z0-9\-\_\&\=]/i', '', strtolower($url)));
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
                'produit_url' => 'URL du produit',
                'produit_prix' => 'Prix TTC du produit',
                'produit_ligne' => 'En ligne ? (0 ou 1)',
                'accessoire_ref' => 'Réf de produit(s) associés'
            ),
            'rubrique' => array()
        );
        for($i=0; $i<=$this->_rubriquesMaxDepth;$i++) {
            $labelTitre = 'Titre de la ';
            $labelUrl = 'URL de la ';
            for($j=0; $j<$i; $j++) {
                $labelTitre.= 'sous-';
                $labelUrl.= 'sous-';
            }
            $labelTitre .= 'rubrique'; $labelUrl .= 'rubrique';
            $fields['rubrique']['rubrique' . $i . '_titre'] = $labelTitre;
            $fields['rubrique']['rubrique' . $i . '_url'] = $labelUrl;
        }
        // Les caractéristiques
        $req = $this->query('
            SELECT
                caracteristique.id,
                caracteristiquedesc.titre
            FROM ' . Caracteristique::TABLE . ' AS caracteristique
                LEFT JOIN ' . Caracteristiquedesc::TABLE . ' ON(
                    caracteristiquedesc.caracteristique=caracteristique.id
                    AND caracteristiquedesc.lang=1
                )
        ');
        while($row = mysql_fetch_object($req)) {
            $fields['ID valeurs de caractéristiques (caracdisp)']['caracteristique_' . $row->id . '_caradispId'] = 'ID ' . $row->titre;
        }

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