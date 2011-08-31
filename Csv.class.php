<?php
class Csv {
    
    public $file = null;
    
    private $_error = null;
    private $_handle = null;
    private $_delimiter = ',';
    
    
    function __construct($file) {
        $this->file = $file;
        return $this->_openFile();
    }
    
    private function _openFile() {
        if(($handle = fopen($this->file, "r")) === false) {
            $this->_error = 'Impossible de trouver ou d\'ouvrir le fichier.';
            return false;   
        }
        $this->_handle = $handle;
        return true;
    }
    
    
    public function getNumCols($linesSearch=1) {
        if(!preg_match('/^[0-9]{1,}$/', $linesSearch)) $linesSearch=1;
        if($linesSearch < 1) $linesSearch = 1; // il faut au moins une ligne

        $numCols = 0; // nombre de colonnes trouvé
        $row = 0;
        while (($data = fgetcsv($this->_handle, 0, $this->_delimiter)) !== false) {
            $numCols = max($numCols, count($data));
            $row++;
            if($row >= $linesSearch) break;
        }
        return $numCols;
    }   
    
    public function getPreview($lines=null) {
        $this->gotoFirstLine();
        $lines = (preg_match('/^[0-9]{1,}$/', $lines)) ? $lines : 6;
        
        $datas = array(); // données du CSV
        $row = 0;
        while (($data = fgetcsv($this->_handle, 0, $this->_delimiter)) !== false) {
            $datas[] = $data;
            $row++;
            if($row >= $lines) break;
        }
        return $datas;
    }
    
    public function getLineData() {
        $data = fgetcsv($this->_handle, 0, $this->_delimiter);
        if($data === false) return false;
        return $data;
    }
    
    public function gotoFirstLine() {
        fseek($this->_handle, 0);
        return $this;
    }
}