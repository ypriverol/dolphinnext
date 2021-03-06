<?php
require_once(__DIR__."/../api/funcs.php");
require_once(__DIR__."/../../config/config.php");
class dbfuncs {
    private $dbhost = DBHOST;
    private $db = DB;
    private $dbuser = DBUSER;
    private $dbpass = DBPASS;
    private $dbport = DBPORT;
    private $run_path = RUNPATH;
    private $tmp_path = TEMPPATH;
    private $ssh_path = SSHPATH;
    private $ssh_settings = "-oStrictHostKeyChecking=no -q -oChallengeResponseAuthentication=no -oBatchMode=yes -oPasswordAuthentication=no -oConnectTimeout=3";
    private $amz_path = AMZPATH;
    private $amazon = AMAZON;
    private $next_ver = NEXTFLOW_VERSION;
    private static $link;

    function __construct() {
        if (!isset(self::$link)) {
            self::$link = new mysqli($this->dbhost, $this->dbuser, $this->dbpass, $this->db, $this->dbport);
            // check connection
            if (mysqli_connect_errno()) {
                exit('Connect failed: ' . mysqli_connect_error());
            }
        }
    }

    // __destruct removed for unit testing
    //    function __destruct() {
    //        if (isset(self::$link)) {
    //            self::$link->close();
    //        }
    //    }
    function runSQL($sql)
    {
        $link = new mysqli($this->dbhost, $this->dbuser, $this->dbpass, $this->db);
        // check connection
        if (mysqli_connect_errno()) {
            exit('Connect failed: '. mysqli_connect_error());
        }
        $result=self::$link->query($sql);
        $link->close();

        if (!$result) {
            trigger_error('Database Error: ' . self::$link->error);
        }
        if ($result && $result!="1")
        {
            return $result;
        }
        return json_encode (json_decode ("{}"));
    }
    function queryTable($sql)
    {
        $data = array();
        if ($res = $this->runSQL($sql))
        {
            while(($row=$res->fetch_assoc()))
            {
                if (isset($row['sname'])){
                    $row['sname'] = htmlspecialchars_decode($row['sname'], ENT_QUOTES);
                }
                $data[]=$row;
            }

            $res->close();
        }
        return json_encode($data);
    }

    function queryAVal($sql){
        $res = $this->runSQL($sql);
        if (is_object($res)){
            $num_rows =$res->num_rows;
            if (is_object($res) && $num_rows>0){
                $row=$res->fetch_array();
                return $row[0];
            }
        }
        return "0";
    }

    function insTable($sql)
    {
        $data = array();

        if ($res = $this->runSQL($sql))
        {
            $insertID = self::$link->insert_id;
            $data = array('id' => $insertID);
        }
        return json_encode($data);
    }

    function writeLog($uuid,$text,$mode, $filename){
        $file = fopen("{$this->run_path}/$uuid/run/$filename", $mode);
        fwrite($file, $text."\n");
        fclose($file);
    }
    //$img: path of image
    //$singu_save=true to overwrite on image
    function imageCmd($singu_cache, $img, $singu_save, $type, $profileType,$profileId,$ownerID){
        if ($type == 'singularity'){
            preg_match("/shub:\/\/(.*)/", $img, $matches);
            if (!empty($matches[1])){
                $singuPath = '~';
                if ($profileType == "amazon"){
                    $amzData=$this->getProfileAmazonbyID($profileId, $ownerID);
                    $amzDataArr=json_decode($amzData,true);
                    $singuPath = $amzDataArr[0]["shared_storage_mnt"]; // /mnt/efs
                }
                if (!empty($singu_cache)){
                    $singuPath = $singu_cache;
                }
                $imageName = str_replace("/","-",$matches[1]);
                $image = $singuPath.'/.dolphinnext/singularity/'.$imageName;
                if ($singu_save == "true"){
                    $cmd = "mkdir -p $singuPath/.dolphinnext/singularity && cd $singuPath/.dolphinnext/singularity && rm -f ".$imageName.".simg && singularity pull --name ".$imageName.".simg ".$img;
                } else {
                    $cmd = "mkdir -p $singuPath/.dolphinnext/singularity && cd $singuPath/.dolphinnext/singularity && singularity pull --name ".$imageName.".simg ".$img;
                }
                return $cmd;
            }
        }
    }

    //type:w creates new file
    function createDirFile ($pathDir, $fileName, $type, $text){
        if ($pathDir != ""){
            if (!file_exists($pathDir)) {
                mkdir($pathDir, 0777, true);
            }
        }
        if ($fileName != ""){
            $file = fopen("$pathDir/$fileName", $type);
            fwrite($file, $text);
            fclose($file);
            chmod("$pathDir/$fileName", 0755);
        }
    }

    //if logArray not exist than send empty ""
    function runCommand ($cmd, $logName, $logArray) {
        $pid_command = popen($cmd, 'r');
        $pid = fread($pid_command, 2096);
        pclose($pid_command);
        if (empty($logArray)){
            $log_array = array($logName => $pid);
        } else {
            $log_array[$logName] = $pid;
        }
        return $log_array;
    }

    //full path for file
    function readFile($path){
        $content = "";
        if (file_exists($path)){
            $handle = fopen($path, 'r');
            if (filesize($path) > 0){
                $content = fread($handle, filesize($path));
            }
            fclose($handle);
            return $content;
        } else {
            return null;
        }
    }

    function randomPassword() {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
        $pass = array(); //remember to declare $pass as an array
        $alphaLength = strlen($alphabet) - 1; //put the length -1 in cache
        for ($i = 0; $i < 8; $i++) {
            $n = rand(0, $alphaLength);
            $pass[] = $alphabet[$n];
        }
        return implode($pass); //turn the array into a string
    }

    function getS3config ($project_pipeline_id, $attempt, $ownerID){
        $allinputs = json_decode($this->getProjectPipelineInputs($project_pipeline_id, $ownerID));
        $s3configFileDir = "";
        $amazon_cre_id_Ar = array();
        foreach ($allinputs as $inputitem):
        $collection_id = $inputitem->{'collection_id'};
        if (!empty($collection_id)){
            $allfiles= json_decode($this->getCollectionFiles($collection_id, $ownerID));
            foreach ($allfiles as $fileData):
            $file_dir = $fileData->{'file_dir'};
            $s3_archive_dir = $fileData->{'s3_archive_dir'};
            if (preg_match("/s3:/i",$file_dir)){
                $s3Data = explode("\t", $file_dir);
                $s3Path = trim($s3Data[0]);
                $amazon_cre_id = trim($s3Data[1]);
                if (!in_array($amazon_cre_id, $amazon_cre_id_Ar)){ $amazon_cre_id_Ar[] = $amazon_cre_id; }
            } 
            if (preg_match("/s3:/i",$s3_archive_dir)){
                $s3Data = explode("\t", $s3_archive_dir);
                $s3Path = trim($s3Data[0]);
                $amazon_cre_id = trim($s3Data[1]);
                if (!in_array($amazon_cre_id, $amazon_cre_id_Ar)){ $amazon_cre_id_Ar[] = $amazon_cre_id; }
            }
            endforeach;
        }
        endforeach;

        foreach ($amazon_cre_id_Ar as $amazon_cre_id):
        if (!empty($amazon_cre_id)){
            $amz_data = json_decode($this->getAmzbyID($amazon_cre_id, $ownerID));
            foreach($amz_data as $d){
                $access = $d->amz_acc_key;
                $d->amz_acc_key = trim($this->amazonDecode($access));
                $secret = $d->amz_suc_key;
                $d->amz_suc_key = trim($this->amazonDecode($secret));
            }
            $access_key = $amz_data[0]->{'amz_acc_key'};
            $secret_key = $amz_data[0]->{'amz_suc_key'};
            $confText = "access_key=$access_key\nsecret_key=$secret_key\n";
            $s3configDir = "{$this->amz_path}/config/run{$project_pipeline_id}/initialrun";
            $s3configFileDir = $s3configDir;
            $s3tmpFile = "$s3configDir/.conf.$amazon_cre_id";
            if (!file_exists($s3configDir)) {
                mkdir($s3configDir, 0700, true);
            }
            $file = fopen($s3tmpFile, 'w');//creates new file
            fwrite($file, $confText);
            fclose($file);
            chmod($s3tmpFile, 0700);
        }
        endforeach;

        return $s3configFileDir;
    }
    
    
    function getCluAmzData($profileId, $profileType, $ownerID) {
        if ($profileType == 'cluster'){
            $cluData=$this->getProfileClusterbyID($profileId, $ownerID);
            $cluDataArr=json_decode($cluData,true);
            $connect = $cluDataArr[0]["username"]."@".$cluDataArr[0]["hostname"];
        } else if ($profileType == 'amazon'){
            $cluData=$this->getProfileAmazonbyID($profileId, $ownerID);
            $cluDataArr=json_decode($cluData,true);
            $connect = $cluDataArr[0]["ssh"];
        }
        $ssh_port = !empty($cluDataArr[0]["port"]) ? " -p ".$cluDataArr[0]["port"] : "";
        $scp_port = !empty($cluDataArr[0]["port"]) ? " -P ".$cluDataArr[0]["port"] : "";
        return array($connect, $ssh_port, $scp_port, $cluDataArr);
    }

    function initialRunScript ($project_pipeline_id, $attempt, $ownerID){
        $script="";
        $parallel = true;
        $proPipeAll = json_decode($this->getProjectPipelines($project_pipeline_id,"",$ownerID,""));
        $outdir = $proPipeAll[0]->{'output_dir'};
        $profile= $proPipeAll[0]->{'profile'};
        if (!empty($profile)){
            //if $profile eq "amazon" then allow s3 backupdir download.
            $profile = substr($profile, 0, strpos($profile, "-")); //cluster or amazon
        }
        $run_dir = "$outdir/run{$project_pipeline_id}";
        $allinputs = json_decode($this->getProjectPipelineInputs($project_pipeline_id, $ownerID));
        $file_name = array();
        $file_dir = array();
        $file_type = array();
        $files_used = array();
        $archive_dir = array();
        $s3_archive_dir = array();
        $collection_type = array();
        foreach ($allinputs as $inputitem):
        $collection_id = $inputitem->{'collection_id'};
        if (!empty($collection_id)){
            $allfiles= json_decode($this->getCollectionFiles($collection_id, $ownerID));
            foreach ($allfiles as $fileData):
            $file_name[] = $fileData->{'name'};
            $file_dir[] = $fileData->{'file_dir'};
            $file_type[] = $fileData->{'file_type'};
            $files_used[] = $fileData->{'files_used'};
            $archive_dir[] = $fileData->{'archive_dir'};
            $s3_archive_dir[] = $fileData->{'s3_archive_dir'};
            $collection_type[] = $fileData->{'collection_type'};
            endforeach;
        }
        endforeach;
        if (!empty($file_name)) {
            if ($parallel == true){
                $file_nameS = "Channel.from(\"'" . implode ( "'\", \"'", $file_name ) . "'\")";
                $file_dirS = "Channel.from(\"'" . implode ( "'\", \"'", $file_dir ) . "'\")";
                $file_typeS = "Channel.from(\"'" . implode ( "'\", \"'", $file_type ) . "'\")";
                $files_usedS = "Channel.from(\"'" . implode ( "'\", \"'", $files_used ) . "'\")";
                $archive_dirS = "Channel.from(\"'" . implode ( "'\", \"'", $archive_dir ) . "'\")";
                $s3_archive_dirS = "Channel.from(\"'" . implode ( "'\", \"'", $s3_archive_dir ) . "'\")";
                $collection_typeS = "Channel.from(\"'" . implode ( "'\", \"'", $collection_type ) . "'\")";
                //for all file control
                $file_name_allS = "Channel.value(\"'" . implode ( "', '", $file_name ) . "'\")";
                $file_type_allS = "Channel.value(\"'" . implode ( "', '", $file_type ) . "'\")";
                $collection_type_allS = "Channel.value(\"'" . implode ( "', '", $collection_type ) . "'\")";
            } else {
                $file_nameS = "Channel.value(\"'" . implode ( "', '", $file_name ) . "'\")";
                $file_dirS = "Channel.value(\"'" . implode ( "', '", $file_dir ) . "'\")";
                $file_typeS = "Channel.value(\"'" . implode ( "', '", $file_type ) . "'\")";
                $files_usedS = "Channel.value(\"'" . implode ( "', '", $files_used ) . "'\")";
                $archive_dirS = "Channel.value(\"'" . implode ( "', '", $archive_dir ) . "'\")";
                $s3_archive_dirS = "Channel.value(\"'" . implode ( "', '", $s3_archive_dir ) . "'\")";
                $collection_typeS = "Channel.value(\"'" . implode ( "', '", $collection_type ) . "'\")";
                //for all file control
                $file_name_allS = $file_nameS;
                $file_type_allS = $file_typeS;
                $collection_type_allS  = $collection_typeS;
            }

            $script = "file_name = $file_nameS;
        file_dir = $file_dirS;
        file_type = $file_typeS;
        files_used = $files_usedS;
        archive_dir = $archive_dirS;
        s3_archive_dir = $s3_archive_dirS;
        collection_type = $collection_typeS;
        file_name_all = $file_name_allS;
        file_type_all = $file_type_allS;
        collection_type_all = $collection_type_allS;

        process initialRun {
          errorStrategy 'retry'
          maxRetries 2

          input:
          val file_name from file_name
          val file_dir from file_dir
          val file_type from file_type
          val files_used from files_used
          val archive_dir from archive_dir
          val s3_archive_dir from s3_archive_dir
          val collection_type from collection_type
          val file_name_all from file_name_all
          val file_type_all from file_type_all
          val collection_type_all from collection_type_all

          output:
          val('success.$attempt')  into success
          shell:
          '''
          #!/usr/bin/env perl
          use strict;
          use File::Basename;
          use Getopt::Long;
          use Pod::Usage;
          use Data::Dumper;
          use File::Copy;
          use File::Path qw( make_path );
          use File::Compare;

          my \$run_dir = \"$run_dir\";
          my \$profile = \"$profile\";
          my \$input_dir = \"\$run_dir/inputs\";
          my \$s3down_dir_prefix = \"\$input_dir/.tmp\";
          my \$s3upload_dir = \"\$input_dir/.s3up\";
          my @file_name = (!{file_name});
          my @file_dir = (!{file_dir});
          my @file_type = (!{file_type});
          my @files_used = (!{files_used});
          my @archive_dir = (!{archive_dir});
          my @s3_archive_dir = (!{s3_archive_dir});
          my @collection_type = (!{collection_type});
          my @file_name_all = (!{file_name_all});
          my @file_type_all = (!{file_type_all});
          my @collection_type_all = (!{collection_type_all});


          if ( !-d \$input_dir ) {
            runCommand(\"mkdir -p \$input_dir\");
          }

          my %passHash;    ## Keep record of completed operation
          my %validInputHash; ## Keep record of files as fullpath

          for ( my \$i = 0 ; \$i <= \$#file_name ; \$i++ ) {
            my \$fileType        = \$file_type[\$i];
            my \$archiveDir      = trim( \$archive_dir[\$i] );
            my \$s3_archiveDir      = trim( \$s3_archive_dir[\$i] );
            my @fileAr          = split( / \\\| /, \$files_used[\$i], -1 );
            my @fullfileAr      = ();
            my @fullfileArR1    = ();
            my @fullfileArR2    = ();
            my \$inputDirCheck   = \"false\";
            my \$archiveDirCheck = \"false\";
            my \$s3_archiveDirCheck = \"\";
            my \$inputFile       = \"\";
            my \$inputFile1      = \"\";
            my \$inputFile2      = \"\";
            my \$archFile        = \"\";
            my \$archFile1       = \"\";
            my \$archFile2       = \"\";

            ## first check input folder, archive_dir and s3_archivedir for expected files
            if ( \$collection_type[\$i] eq \"single\" ) {
              \$inputFile = \"\$input_dir/\$file_name[\$i].\$fileType\";
              if ( checkFile(\$inputFile) && checkFile(\"\$input_dir/.success_\$file_name[\$i]\")) {
                \$inputDirCheck = \"true\";
              } else {
                runCommand(\"rm -f \$inputFile \$input_dir/.success_\$file_name[\$i]\");
              }
            }
            elsif ( \$collection_type[\$i] eq \"pair\" ) {
              \$inputFile1                  = \"\$input_dir/\$file_name[\$i].R1.\$fileType\";
              \$inputFile2                  = \"\$input_dir/\$file_name[\$i].R2.\$fileType\";
              if ( checkFile(\$inputFile1) && checkFile(\$inputFile2) && checkFile(\"\$input_dir/.success_\$file_name[\$i]\")) {
                \$inputDirCheck = \"true\";
              } else {
                runCommand(\"rm -f \$inputFile1 \$inputFile2 \$input_dir/.success_\$file_name[\$i]\");
              }
            }
            if ( \$s3_archiveDir ne \"\" ) {
                my @s3_archiveDirData = split( /\t/, \$s3_archiveDir);
                my \$s3Path = \$s3_archiveDirData[0]; 
                my \$confID = \$s3_archiveDirData[1];
                if ( \$collection_type[\$i] eq \"single\" ) {
                \$archFile = \"\$s3Path/\$file_name[\$i].\$fileType\";
                if ( checkS3File(\"\$archFile.gz\", \$confID) && checkS3File(\"\$archFile.gz.count\", \$confID) && checkS3File(\"\$archFile.gz.md5sum\", \$confID)) {
                    \$s3_archiveDirCheck = \"true\";
                } else {
                    \$s3_archiveDirCheck = \"false\";
                }
              }
              elsif ( \$collection_type[\$i] eq \"pair\" ) {
                \$archFile1 = \"\$s3Path/\$file_name[\$i].R1.\$fileType\";
                \$archFile2 = \"\$s3Path/\$file_name[\$i].R2.\$fileType\";
                if ( checkS3File(\"\$archFile1.gz\", \$confID) && checkS3File(\"\$archFile1.gz.count\", \$confID) && checkS3File(\"\$archFile1.gz.md5sum\", \$confID) && checkS3File(\"\$archFile2.gz\",\$confID) && checkS3File(\"\$archFile2.gz.count\",\$confID) && checkS3File(\"\$archFile2.gz.md5sum\",\$confID)) {
                    \$s3_archiveDirCheck = \"true\";
                } else {
                    \$s3_archiveDirCheck = \"false\";
                }
              }
            }
            ## if s3_archiveDirCheck is false (not '') and \$archiveDir eq \"\" then act as if \$archiveDir defined as s3upload_dir
            ## for s3 upload first archive files need to be prepared. 
            ## If \$archiveDir is not empty then copy these files to \$s3upload_dir.
            ## else \$archiveDir is empty create archive files in \$s3upload_dir.
            if ( \$archiveDir eq \"\" && \$s3_archiveDirCheck eq \"false\") {
                \$archiveDir = \"\$s3upload_dir\";
            }

            if ( \$archiveDir ne \"\" ) {
              if ( !-d \$archiveDir ) {
                runCommand(\"mkdir -p \$archiveDir\");
              }
              if ( \$collection_type[\$i] eq \"single\" ) {
                \$archFile = \"\$archiveDir/\$file_name[\$i].\$fileType\";
                if ( checkFile(\"\$archFile.gz\") && checkFile(\"\$archFile.gz.count\")) {
                  \$archiveDirCheck = \"true\";
                } elsif ( checkFile(\"\$archFile.gz\") || checkFile(\"\$archFile.gz.count\") ) {
                  ## if only one of them exist then remove files
                  runCommand(\"rm -f \$archFile.gz\");
                }
              }
              elsif ( \$collection_type[\$i] eq \"pair\" ) {
                \$archFile1 = \"\$archiveDir/\$file_name[\$i].R1.\$fileType\";
                \$archFile2 = \"\$archiveDir/\$file_name[\$i].R2.\$fileType\";
                if ( checkFile(\"\$archFile1.gz\") && checkFile(\"\$archFile1.gz.count\") && checkFile(\"\$archFile2.gz\") && checkFile(\"\$archFile2.gz.count\") ) {
                  \$archiveDirCheck = \"true\";
                } elsif ( checkFile(\"\$archFile1.gz\") || checkFile(\"\$archFile2.gz\") ) {
                  ## if only one of them exist then remove files
                  runCommand(\"rm -f \$archFile1.gz \$archFile2.gz\");
                }
              }
            }

            print \"inputDirCheck for \$file_name[\$i]: \$inputDirCheck\\\\n\";
            print \"archiveDirCheck for \$file_name[\$i]: \$archiveDirCheck\\\\n\";
            print \"s3_archiveDirCheck for \$file_name[\$i]: \$s3_archiveDirCheck\\\\n\";

            if (   \$inputDirCheck eq \"true\" && \$archiveDirCheck eq \"false\" && \$archiveDir ne \"\" ){
              ## remove inputDir files and cleanstart
              if ( \$collection_type[\$i] eq \"single\" ) {
                runCommand(\"rm \$inputFile\");
              }
              elsif ( \$collection_type[\$i] eq \"pair\" ) {
                runCommand(\"rm \$inputFile1\");
                runCommand(\"rm \$inputFile2\");
              }
              \$inputDirCheck = \"false\";
            }

            if ( \$inputDirCheck eq \"false\" && \$archiveDirCheck eq \"true\" ) {
                if ( \$collection_type[\$i] eq \"single\" ) {
                    arch2Input (\"\$archFile.gz\", \"\$inputFile.gz\", \$s3_archiveDirCheck, \$s3_archiveDir);
                } elsif ( \$collection_type[\$i] eq \"pair\" ) {
                    arch2Input (\"\$archFile1.gz\", \"\$inputFile1.gz\", \$s3_archiveDirCheck, \$s3_archiveDir);
                    arch2Input (\"\$archFile2.gz\", \"\$inputFile2.gz\", \$s3_archiveDirCheck, \$s3_archiveDir);
                }
                runCommand(\"touch \$input_dir/.success_\$file_name[\$i]\");
                \$passHash{ \$file_name[\$i] } = \"passed\";
            }
            ## if \$s3_archiveDirCheck eq \"true\" && \$archiveDirCheck eq \"false\" && \$profile eq \"amazon\": no need to check input file existance. Download s3 file and call it archived file.
            elsif ( \$inputDirCheck eq \"false\" && \$archiveDirCheck eq \"false\" && \$s3_archiveDirCheck eq \"true\" && \$profile eq \"amazon\") {
                if ( \$collection_type[\$i] eq \"single\" ) {
                    my \$s3tmp_dir_sufx = s3downCheck(\$s3_archiveDir, \"\$file_name[\$i].\$fileType.gz\");
                    my \$archFile = \$s3tmp_dir_sufx . \"/\" . \"\$file_name[\$i].\$fileType\";
                    arch2Input (\"\$archFile.gz\", \"\$inputFile.gz\", \$s3_archiveDirCheck, \$s3_archiveDir);
                } elsif ( \$collection_type[\$i] eq \"pair\" ) {
                    my \$s3tmp_dir_sufx1 = s3downCheck(\$s3_archiveDir, \"\$file_name[\$i].R1.\$fileType.gz\");
                    my \$archFile1 = \$s3tmp_dir_sufx1 . \"/\" . \"\$file_name[\$i].R1.\$fileType\";
                    my \$s3tmp_dir_sufx2 = s3downCheck(\$s3_archiveDir, \"\$file_name[\$i].R2.\$fileType.gz\");
                    my \$archFile2 = \$s3tmp_dir_sufx2 . \"/\" . \"\$file_name[\$i].R2.\$fileType\";
                    arch2Input (\"\$archFile1.gz\", \"\$inputFile1.gz\", \$s3_archiveDirCheck, \$s3_archiveDir);
                    arch2Input (\"\$archFile2.gz\", \"\$inputFile2.gz\", \$s3_archiveDirCheck, \$s3_archiveDir);
                }
                runCommand(\"touch \$input_dir/.success_\$file_name[\$i]\");
                \$passHash{ \$file_name[\$i] } = \"passed\";
            }
            elsif ( \$inputDirCheck eq \"false\" && \$archiveDirCheck eq \"false\" ) {
              ##create new collection files
              ##Keep full path of files that needs to merge
              for ( my \$k = 0 ; \$k <= \$#fileAr ; \$k++ ) {
                if ( \$collection_type[\$i] eq \"single\" ) {
                  ## for GEO files: file_dir will be empty so @fullfileAr will be empty.
                  if (\$file_dir[\$i] =~ m/s3:/i ){
                    my \$s3tmp_dir_sufx = s3downCheck(\$file_dir[\$i], \$fileAr[\$k]);
                    push @fullfileAr, \$s3tmp_dir_sufx . \"/\" . \$fileAr[\$k];
                  } elsif (trim( \$file_dir[\$i] ne \"\")){
                    push @fullfileAr, \$file_dir[\$i] . \"/\" . \$fileAr[\$k];
                  }

                }
                elsif ( \$collection_type[\$i] eq \"pair\" ) {
                  if (\$file_dir[\$i] =~ m/s3:/i ){
                    my @pair = split( /,/, \$fileAr[\$k], -1 );
                    my \$s3tmp_dir_sufx1 = s3downCheck(\$file_dir[\$i], \$pair[0]);
                    my \$s3tmp_dir_sufx2 = s3downCheck(\$file_dir[\$i], \$pair[1]);
                    print \$s3tmp_dir_sufx1;
                    push @fullfileArR1, \$s3tmp_dir_sufx1 . \"/\" . \$pair[0];
                    push @fullfileArR2, \$s3tmp_dir_sufx2 . \"/\" . \$pair[1];
                  } elsif (trim( \$file_dir[\$i] ne \"\")){
                    my @pair = split( /,/, \$fileAr[\$k], -1 );
                    push @fullfileArR1, \$file_dir[\$i] . \"/\" . \$pair[0];
                    push @fullfileArR2, \$file_dir[\$i] . \"/\" . \$pair[1];
                  }
                }
              }
              if ( \$archiveDir ne \"\") {
                ##merge files in archive dir then copy to inputdir
                my \$cat = \"cat\";
                ##Don't run mergeGzip for GEO files
                if (scalar @fullfileAr != 0 && \$collection_type[\$i] eq \"single\"){
                  my \$filestr = join( ' ', @fullfileAr );
                  \$cat = \"zcat -f\" if ( \$filestr =~ /\\\.gz/ );
                  mergeGzipCountMd5sum( \$cat, \$filestr, \$archFile );
                } elsif ( scalar @fullfileArR1 != 0 && \$collection_type[\$i] eq \"pair\" ) {
                  my \$filestrR1 = join( ' ', @fullfileArR1 );
                  my \$filestrR2 = join( ' ', @fullfileArR2 );
                  \$cat = \"zcat -f\" if ( \$filestrR1 =~ /\\\.gz/ );
                  mergeGzipCountMd5sum( \$cat, \$filestrR1, \$archFile1 );
                  mergeGzipCountMd5sum( \$cat, \$filestrR2, \$archFile2 );
                } else {
                  ##Run fastqdump and CountMd5sum for GEO files
                  my \$gzip = \"--gzip\";
                  if ( \$collection_type[\$i] eq \"single\" ) {
                    fasterqDump(\$gzip, \$archiveDir, \$fileAr[0], \$file_name[\$i], \$collection_type[\$i]);
                    countMd5sum(\"\$archFile\");
                  }
                  elsif ( \$collection_type[\$i] eq \"pair\" ) {
                    fasterqDump(\$gzip, \$archiveDir, \$fileAr[0], \$file_name[\$i], \$collection_type[\$i]);
                    countMd5sum(\"\$archFile1\");
                    countMd5sum(\"\$archFile2\");
                  }
                }
                if ( \$collection_type[\$i] eq \"single\" ) {
                    arch2Input (\"\$archFile.gz\", \"\$inputFile.gz\", \$s3_archiveDirCheck, \$s3_archiveDir);
                }
                elsif ( \$collection_type[\$i] eq \"pair\" ) {
                    arch2Input (\"\$archFile1.gz\", \"\$inputFile1.gz\", \$s3_archiveDirCheck, \$s3_archiveDir);
                    arch2Input (\"\$archFile2.gz\", \"\$inputFile2.gz\", \$s3_archiveDirCheck, \$s3_archiveDir);
                }
              }
              else {
                ##archive_dir is not defined then merge files in input_dir
                my \$cat = \"cat\";
                ##Don't run merge for GEO files
                if ( scalar @fullfileAr != 0 && \$collection_type[\$i] eq \"single\" ) {
                  my \$filestr = join( ' ', @fullfileAr );
                  \$cat = \"zcat -f\" if ( \$filestr =~ /\\\.gz/ );
                  runCommand(\"\$cat \$filestr > \$inputFile\");
                } elsif ( scalar @fullfileArR1 != 0 && \$collection_type[\$i] eq \"pair\" ) {
                  my \$filestrR1 = join( ' ', @fullfileArR1 );
                  my \$filestrR2 = join( ' ', @fullfileArR2 );
                  \$cat = \"zcat -f \" if ( \$filestrR1 =~ /\\\.gz/ );
                  runCommand(\"\$cat \$filestrR1 > \$inputFile1\");
                  runCommand(\"\$cat \$filestrR2 > \$inputFile2\");
                } else {
                  ##Run fastqdump without --gzip for GEO files
                  fasterqDump(\"\", \$input_dir, \$fileAr[0], \$file_name[\$i], \$collection_type[\$i]);
                }
              }
              runCommand(\"touch \$input_dir/.success_\$file_name[\$i]\");
              \$passHash{ \$file_name[\$i] } = \"passed\";
            }
            elsif (\$inputDirCheck eq \"true\"
            && \$archiveDirCheck eq \"false\"
            && \$archiveDir eq \"\" )
            {
              \$passHash{ \$file_name[\$i] } = \"passed\";
            }
            elsif ( \$inputDirCheck eq \"true\" && \$archiveDirCheck eq \"true\" ) {
                if (\$s3_archiveDirCheck eq \"false\"){
                    if ( \$collection_type[\$i] eq \"single\" ) {
                        prepS3Upload (\"\$archFile.gz\", \"\$archFile.gz.count\", \"\$archFile.gz.md5sum\", \$s3_archiveDir);
                    }
                    elsif ( \$collection_type[\$i] eq \"pair\" ) {
                        prepS3Upload (\"\$archFile1.gz\", \"\$archFile1.gz.count\", \"\$archFile1.gz.md5sum\", \$s3_archiveDir);
                        prepS3Upload (\"\$archFile2.gz\", \"\$archFile2.gz.count\", \"\$archFile2.gz.md5sum\", \$s3_archiveDir);
                    }
                }
              \$passHash{ \$file_name[\$i] } = \"passed\";
            }
          }


          for ( my \$i = 0 ; \$i <= \$#file_name ; \$i++ ) {
            die \"Error 64: please check your input file:\$file_name[\$i]\"
            unless ( \$passHash{ \$file_name[\$i] } eq \"passed\" );
          }


          ##Subroutines

          sub runCommand {
            my (\$com) = @_;
            my \$error = system(\$com);
            if   (\$error) { die \"Command failed: \$error \$com\\\\n\"; }
            else          { print \"Command successful: \$com\\\\n\"; }
          }

          sub checkFile {
            my (\$file) = @_;
            print \"\$file\\\\n\";
            return 1 if ( -e \$file );
            return 0;
          }

          sub checkS3File{
            my ( \$file, \$confID) = @_;
            my \$tmpSufx = \$file;
            \$tmpSufx =~ s/[^A-Za-z0-9]/_/g;
            runCommand (\"mkdir -p \$s3upload_dir && > \$s3upload_dir/.info.\$tmpSufx \");
            my \$err = system (\"s3cmd info --config=\$run_dir/initialrun/.conf.\$confID \$file >\$s3upload_dir/.info.\$tmpSufx 2>&1 \");
            ## if file not found then it will give error
            my \$checkMD5 = 'false';
            if (\$err){
                print \"S3File Not Found: \$file\\\\n\";
                return 0;
            } else {
                open(FILE,\"\$s3upload_dir/.info.\$tmpSufx\");
                if (grep{/MD5/} <FILE>){
                    \$checkMD5 = 'true';
                }
                close FILE;
            }
            return 1 if ( \$checkMD5 eq 'true' );
            print \"S3File Not Found: \$file\\\\n\";
            return 0;
          }

          sub makeS3Bucket{
            my ( \$bucket, \$confID) = @_;
            my \$err = system (\"s3cmd info --config=\$run_dir/initialrun/.conf.\$confID \$bucket 2>&1 \");
            ## if bucket is not found then it will give error
            my \$check = 'false';
            if (\$err){
                print \"S3bucket Not Found: \$bucket\\\\n\";
                runCommand(\"s3cmd mb --config=\$run_dir/initialrun/.conf.\$confID \$bucket \");
            } 
          }

          sub trim {
            my \$s = shift;
            \$s =~ s/^\\\s+|\\\s+\$//g;
            return \$s;
          }

          sub copyFile {
            my ( \$file, \$target ) = @_;
            runCommand(\"rsync -vazu \$file \$target\");
          }



          sub countMd5sum {
            my (\$inputFile ) = @_;
            runCommand(\"s=\\\\$(zcat \$inputFile.gz|wc -l) && echo \\\\\$((\\\\\$s/4)) > \$inputFile.gz.count && md5sum \$inputFile.gz > \$inputFile.gz.md5sum\");
          }

          sub mergeGzipCountMd5sum {
            my ( \$cat, \$filestr, \$inputFile ) = @_;
            runCommand(\"\$cat \$filestr > \$inputFile && gzip \$inputFile\");
            countMd5sum(\$inputFile);
          }

          sub parseMd5sum{
            my ( \$path )  = @_;
            open my \$file, '<', \$path; 
            my \$firstLine = <\$file>; 
            close \$file;
            my @arr = split(' ', \$firstLine);
            my \$md5sum = \$arr[0];
            return \$md5sum;
          }



          sub md5sumCompare{
            my ( \$path1, \$path2) = @_;
            my \$md5sum1 = parseMd5sum(\$path1);
            my \$md5sum2 = parseMd5sum(\$path2);
            if (\$md5sum1 eq \$md5sum2 && \$md5sum1 ne \"\"){
                print \"MD5sum check successful for \$path1 vs \$path2: \$md5sum1 vs \$md5sum2 \\\\n\";
                return 'true';
            } else {
                print \"MD5sum check failed for \$path1 vs \$path2: \$md5sum1 vs \$md5sum2 \\\\n\";
                return 'false';
            }
          }

          sub S3UploadCheck{
            my ( \$localpath, \$archFileMd5sum, \$s3Path, \$confID, \$upload_path) = @_;
              my \$file = basename(\$localpath);  
              runCommand(\"mkdir -p \$upload_path/\$file.chkS3Up && cd \$upload_path/\$file.chkS3Up && s3cmd get --force --config=\$upload_path/.conf.\$confID \$s3Path/\$file\");
              runCommand(\"cd \$upload_path/\$file.chkS3Up && md5sum \$upload_path/\$file.chkS3Up/\$file > \$upload_path/\$file.chkS3Up/\$file.md5sum  \");
              if (md5sumCompare(\$archFileMd5sum, \"\$upload_path/\$file.chkS3Up/\$file.md5sum\") eq 'true') {
                 runCommand(\"rm -f \${s3upload_dir}/.s3fail_\$file && touch \${s3upload_dir}/.s3success_\$file\");
                 return 'true';
              } else {
                 runCommand(\"rm -f \${s3upload_dir}/.s3success_\$file && touch \${s3upload_dir}/.s3fail_\$file\");
                 return 'false';
              }

          }

          sub S3Upload{
          my ( \$path, \$s3Path, \$confID, \$upload_path) = @_;
              my \$file = basename(\$path);  
              runCommand(\"s3cmd put --config=\$upload_path/.conf.\$confID \$path \$s3Path/\$file \");
          }

          sub prepS3Upload{
          my ( \$archFile, \$archFileCount, \$archFileMd5sum, \$s3_archiveDir ) = @_;
            my @data = split( /\t/, \$s3_archiveDir);
            my \$s3Path = \$data[0]; 
            my \$confID = \$data[1];
            my \$upload_path = \${s3upload_dir};
            print \"upload_path: \$upload_path\\\\n\";
            runCommand(\"mkdir -p \$upload_path && cd \$upload_path && rsync -vazu \$archFile \$archFileCount \$archFileMd5sum . && rsync -vazu \$run_dir/initialrun/.conf.\$confID . \");
            my \$bucket=\$s3Path;
            \$bucket=~ s/(s3:\\\/\\\/)|(S3:\\\/\\\/)//;
            my @arr = split('/', \$bucket);
            \$bucket = 's3://'.\$arr[0];
            ##make bucket if not exist
            makeS3Bucket(\$bucket, \$confID);
            my \$upCheck = 'false';
            for ( my \$c = 1 ; \$c <= 3 ; \$c++ ) {
                S3Upload(\$archFile, \$s3Path, \$confID, \$upload_path);
                S3Upload(\$archFileCount, \$s3Path, \$confID, \$upload_path);
                S3Upload(\$archFileMd5sum, \$s3Path, \$confID, \$upload_path);
                \$upCheck = S3UploadCheck(\$archFile, \$archFileMd5sum, \$s3Path, \$confID, \$upload_path);
                if (\$upCheck eq 'true'){
                    last;
                } 
            } 
            if (\$upCheck ne 'true'){
                die \"S3 upload failed for 3 times. MD5sum not matched. \";
            }
          }

          ## copy files from achive directory to input directory and extract them in input_dir
          sub arch2Input {
            my ( \$archFile, \$inputFile, \$s3_archiveDirCheck, \$s3_archiveDir ) = @_;
            copyFile( \"\$archFile\", \"\$inputFile\" );
            runCommand(\"gunzip \$inputFile\");
            if (\$s3_archiveDirCheck eq \"false\"){
                prepS3Upload(\"\$archFile\", \"\$archFile.count\", \"\$archFile.md5sum\", \$s3_archiveDir);
            }
          }

          sub s3down {
            my ( \$s3PathConf, \$file_name ) = @_;
            ##first remove tmp files?
            my @data = split( /\t/, \$s3PathConf);
            my \$s3Path = \$data[0];
            my \$tmpSufx = \$s3Path;
            \$tmpSufx =~ s/[^A-Za-z0-9]/_/g; 
            my \$confID = \$data[1];
            my \$down_path = \${s3down_dir_prefix}.\${tmpSufx};
            runCommand(\"mkdir -p \$down_path && cd \$down_path && s3cmd get --force --config=\$run_dir/initialrun/.conf.\$confID \$s3Path/\$file_name\");
            print \"down_path: \$down_path\n\";
            return \$down_path;
          }

          sub s3downCheck {
            my ( \$s3PathConf, \$file_name ) = @_;
            my \$downCheck = 'false';
            my \$down_path = s3down(\$s3PathConf, \$file_name);
            ## check if md5sum is exist
            my @data = split( /\t/, \$s3PathConf);
            my \$s3Path = \$data[0];
            my \$confID = \$data[1];
            for ( my \$c = 1 ; \$c <= 3 ; \$c++ ) {
                print \"##s3downCheck \$c started: \$down_path\n\";
                my \$err = system (\"s3cmd info --config=\$run_dir/initialrun/.conf.\$confID \$s3Path/\$file_name.md5sum 2>&1 \");
                ## if error occurs, md5sum file is not found in s3. So md5sum-check will be skipped.
                if (\$err){
                    \$downCheck = 'true';
                } else {
                    ## if error not occurs, md5sum file is found in s3. So download and check md5sum.
                    my \$down_path_md5 = s3down(\$s3PathConf, \"\$file_name.md5sum\");
                    print \"##s3downCheck down_path: \$down_path\n\";
                    print \"##s3downCheck down_path_md5: \$down_path_md5\n\";
                    runCommand(\"md5sum \$down_path/\$file_name > \$down_path/\$file_name.md5sum.checkup  \");
                    if (md5sumCompare(\"\$down_path_md5/\$file_name.md5sum\", \"\$down_path/\$file_name.md5sum.checkup\") eq 'true') {
                        \$downCheck = 'true';
                    } else {
                        \$downCheck = 'false';
                    }
                }
                if (\$downCheck eq 'true'){
                    last;
                } else {
                    die \"S3 download failed for 3 times. MD5sum not matched. \";
                }
            }
            return \$down_path;
          }

          sub fasterqDump {
            my ( \$gzip, \$outDir, \$srrID, \$file_name,  \$collection_type) = @_;
            runCommand(\"rm -f \$outDir/\${file_name}.R1.fastq \$outDir/\${file_name}.R2.fastq \$outDir/\${file_name}.fastq \$outDir/\${srrID}_1.fastq \$outDir/\${srrID}_2.fastq \$outDir/\${srrID} \$outDir/\${srrID}.fastq && mkdir -p \\\\\\\$HOME/.ncbi && mkdir -p \${outDir}/sra && echo '/repository/user/main/public/root = \\\\\"\$outDir/sra\\\\\"' > \\\\\\\$HOME/.ncbi/user-settings.mkfg && fasterq-dump -O \$outDir -t \${outDir}/sra --split-3 --skip-technical -o \$srrID \$srrID\");
            if (\$collection_type eq \"pair\"){
              runCommand(\"mv \$outDir/\${srrID}_1.fastq  \$outDir/\${file_name}.R1.fastq \");
              runCommand(\"mv \$outDir/\${srrID}_2.fastq  \$outDir/\${file_name}.R2.fastq \");
              if (\$gzip ne \"\"){
                runCommand(\"gzip  \$outDir/\${file_name}.R1.fastq \");
                runCommand(\"gzip  \$outDir/\${file_name}.R2.fastq \");
              }
            } elsif (\$collection_type eq \"single\"){
              runCommand(\"mv \$outDir/\${srrID}  \$outDir/\${file_name}.fastq \");
              if (\$gzip ne \"\"){
                runCommand(\"gzip  \$outDir/\${file_name}.fastq \");
              }
            }
            runCommand(\"rm -f \${outDir}/sra/sra/\${srrID}.sra.cache\");
          }


          '''
        }

        process cleanUp {

          input:
          val file_name_all from file_name_all
          val file_type_all from file_type_all
          val collection_type_all from collection_type_all
          val successList from success.toList()

          output:
          file('success.$attempt')  into successCleanUp
          shell:
          '''
          #!/usr/bin/env perl
          use strict;
          use File::Basename;
          use Getopt::Long;
          use Pod::Usage;
          use Data::Dumper;

          my \$run_dir = \"$run_dir\";
          my \$input_dir = \"\$run_dir/inputs\";
          my @file_name_all = (!{file_name_all});
          my @file_type_all = (!{file_type_all});
          my @collection_type_all = (!{collection_type_all});


          my %validInputHash; ## Keep record of files as fullpath

          for ( my \$i = 0 ; \$i <= \$#file_name_all ; \$i++ ) {
            my \$fileType        = \$file_type_all[\$i];
            if ( \$collection_type_all[\$i] eq \"single\" ) {
              my \$inputFile = \"\$input_dir/\$file_name_all[\$i].\$fileType\";
              \$validInputHash{\$inputFile} = 1;
            }
            elsif ( \$collection_type_all[\$i] eq \"pair\" ) {
              my \$inputFile1                  = \"\$input_dir/\$file_name_all[\$i].R1.\$fileType\";
              my \$inputFile2                  = \"\$input_dir/\$file_name_all[\$i].R2.\$fileType\";
              \$validInputHash{\$inputFile1} = 1;
              \$validInputHash{\$inputFile2} = 1;
            }
          }

          print Dumper \\\\\\\\%validInputHash;

          ##remove invalid files (not found in @validInputAr) from \$input_dir
          my @inputDirFiles = <\$input_dir/*>;
          foreach my \$file (@inputDirFiles) {
            if ( !exists( \$validInputHash{\$file} ) ) {
              print \"Invalid file \$file will be removed from input directory\\\\n\";
              runCommand(\"rm -rf \$file\");
            }
          }
          ## rm s3 related files
          runCommand(\"rm -rf \$input_dir/.tmp*\");
          runCommand(\"rm -rf \$run_dir/initialrun/.conf*\");
          system('touch success.$attempt');

          sub runCommand {
            my (\$com) = @_;
            my \$error = system(\$com);
            if   (\$error) { die \"Command failed: \$com\\\\n\"; }
            else          { print \"Command successful: \$com\\\\n\"; }
          }

          '''

        }

        workflow.onComplete {
          println \"##Initial run summary##\"
          println \"##Completed at: \$workflow.complete\"
          println \"##Duration: \${workflow.duration}\"
          println \"##Success: \${workflow.success ? 'PASSED' : 'failed' }\"
          println \"##Exit status: \${workflow.exitStatus}\"
          println \"##Waiting for the Next Run..\"
        }
        ";
        }
        return $script;
    }

    //get nextflow input parameters
    function getNextInputs ($executor, $project_pipeline_id, $outdir, $ownerID ){
        $allinputs = json_decode($this->getProjectPipelineInputs($project_pipeline_id, $ownerID));
        $next_inputs="";
        if (!empty($allinputs)){
            foreach ($allinputs as $inputitem):
            $inputName = $inputitem->{'name'};
            $collection_id = $inputitem->{'collection_id'};
            if (!empty($collection_id)){
                $inputsPath = "$outdir/run{$project_pipeline_id}/inputs";
                $allfiles= json_decode($this->getCollectionFiles($collection_id, $ownerID));
                $file_type = $allfiles[0]->{'file_type'};
                $collection_type = $allfiles[0]->{'collection_type'};
                if ($collection_type == "single"){
                    $inputName = "$inputsPath/*.$file_type";
                } else if ($collection_type == "pair"){
                    $inputName = "$inputsPath/*.{R1,R2}.$file_type";
                }
            }

            if ($executor === "local"){
                $next_inputs.="--".$inputitem->{'given_name'}." \\\"".$inputName."\\\" ";
            } else if ($executor !== "local"){
                $next_inputs.="--".$inputitem->{'given_name'}." \\\\\\\"".$inputName."\\\\\\\" ";
            }
            endforeach;
        }
        return $next_inputs;

    }

    //get nextflow executor parameters
    function getNextExecParam($project_pipeline_id,$profileType,$profileId, $initialRunScript, $initialrun_img, $ownerID){
        list($connect, $ssh_port, $scp_port, $cluDataArr) = $this->getCluAmzData($profileId, $profileType, $ownerID);
        $singu_cache = $cluDataArr[0]["singu_cache"];
        $proPipeAll = json_decode($this->getProjectPipelines($project_pipeline_id,"",$ownerID,""));
        $outdir = $proPipeAll[0]->{'output_dir'};
        $proPipeCmd = $proPipeAll[0]->{'cmd'};
        $jobname = html_entity_decode($proPipeAll[0]->{'pp_name'},ENT_QUOTES);
        $singu_check = $proPipeAll[0]->{'singu_check'};
        $initImageCmd = "";
        $imageCmd = "";
        $singu_save = "";
        if ($singu_check == "true"){
            $singu_img = $proPipeAll[0]->{'singu_img'};
            $singu_save = $proPipeAll[0]->{'singu_save'};
            $imageCmd = $this->imageCmd($singu_cache, $singu_img, $singu_save, 'singularity', $profileType,$profileId,$ownerID);
        }
        if (!empty($initialRunScript)){
            $initImageCmd = $this->imageCmd($singu_cache, $initialrun_img, "", 'singularity', $profileType,$profileId,$ownerID);
        }
        //get report options
        $reportOptions = "";
        $withReport = $proPipeAll[0]->{'withReport'};
        $withTrace = $proPipeAll[0]->{'withTrace'};
        $withTimeline = $proPipeAll[0]->{'withTimeline'};
        $withDag = $proPipeAll[0]->{'withDag'};
        if ($withReport == "true"){
            $reportOptions .= " -with-report";
        }
        if ($withTrace == "true"){
            $reportOptions .= " -with-trace";
        }
        if ($withTimeline == "true"){
            $reportOptions .= " -with-timeline";
        }
        if ($withDag == "true"){
            $reportOptions .= " -with-dag dag.html";
        }
        return array($outdir, $proPipeCmd, $jobname, $singu_check, $singu_img, $imageCmd, $initImageCmd, $reportOptions);
    }


    //get username and hostname and exec info for connection
    function getNextConnectExec($profileId,$ownerID, $profileType){
        list($connect, $ssh_port, $scp_port, $cluDataArr) = $this->getCluAmzData($profileId, $profileType, $ownerID);
        $ssh_id = $cluDataArr[0]["ssh_id"];
        $next_path = $cluDataArr[0]["next_path"];
        $profileCmd = $cluDataArr[0]["cmd"];
        $executor = $cluDataArr[0]['executor'];
        $next_time = $cluDataArr[0]['next_time'];
        $next_queue = $cluDataArr[0]['next_queue'];
        $next_memory = $cluDataArr[0]['next_memory'];
        $next_cpu = $cluDataArr[0]['next_cpu'];
        $next_clu_opt = $cluDataArr[0]['next_clu_opt'];
        $executor_job = $cluDataArr[0]['executor_job'];
        return array($connect, $next_path, $profileCmd, $executor,$next_time, $next_queue, $next_memory, $next_cpu, $next_clu_opt, $executor_job,$ssh_id, $ssh_port);
    }

    function getPreCmd ($profileType,$profileCmd,$proPipeCmd, $imageCmd, $initImageCmd){
        $profile_def = "";
        if ($profileType == "amazon"){
            $profile_def = "source /etc/profile && source ~/.bash_profile";
        }
        $nextVer = $this->next_ver;
        $nextVerText = "";
        if (!empty($nextVer)){
            $nextVerText = "export NXF_VER=$nextVer";
        }
        $nextANSILog = "export NXF_ANSI_LOG=false";
        //combine pre-run cmd
        $arr = array($profile_def, $nextVerText, $nextANSILog, $profileCmd, $proPipeCmd, $imageCmd , $initImageCmd);
        $preCmd="";
        for ($i=0; $i<count($arr); $i++) {
            if (!empty($arr[$i]) && !empty($preCmd)){
                $preCmd .= " && ";
            }
            $preCmd .= $arr[$i];
        }
        if (!empty($preCmd)){
            $preCmd .= " && ";
        }

        return $preCmd;
    }

    function getNextPathReal($next_path){
        if (!empty($next_path)){
            $next_path_real = "$next_path/nextflow";
        } else {
            $next_path_real  = "nextflow";
        }
        return $next_path_real;
    }

    function convertToHoursMins($time) {
        $format = '%d:%s';
        settype($time, 'integer');
        if ($time >= 1440) {
            $time = 1440;
        }
        $hours = floor($time/60);
        $minutes = $time%60;
        if ($minutes < 10) {
            $minutes = '0' . $minutes;
        }
        if ($hours < 10) {
            $hours = '0' . $hours;
        }
        return sprintf($format, $hours, $minutes);
    }
    function cleanName($name){
        $name = str_replace("/","_",$name);
        $name = str_replace(" ","",$name);
        $name = str_replace("(","_",$name);
        $name = str_replace(")","_",$name);
        $name = str_replace("\'","_",$name);
        $name = str_replace("\"","_",$name);
        $name = str_replace("\\","_",$name);
        $name = str_replace("&","_",$name);
        $name = str_replace("<","_",$name);
        $name = str_replace(">","_",$name);
        $name = str_replace("-","_",$name);
        $name = substr($name, 0, 9);
        return $name;
    }

    function getMemory($next_memory, $executor){
        if ($executor == "sge"){
            if (!empty($next_memory)){
                $memoryText = "#$ -l h_vmem=".$next_memory."G\\n";
            } else {
                $memoryText = "";
            }
        } else if ($executor == "lsf"){
        }
        return $memoryText;
    }
    function getJobName($jobname, $executor){
        $jobname = $this->cleanName($jobname);
        if ($executor == "sge"){
            if (!empty($jobname)){
                $jobNameText = "#$ -N $jobname\\n";
            } else {
                $jobNameText = "";
            }
        } else if ($executor == "lsf"){
        }
        return $jobNameText;
    }
    function getTime($next_time, $executor){
        if ($executor == "sge"){
            if (!empty($next_time)){
                //$next_time is in minutes convert into hours and minutes.
                $next_time = $this->convertToHoursMins($next_time);
                $timeText = "#$ -l h_rt=$next_time:00\\n";
            } else {
                $timeText = "";
            }
        } else if ($executor == "lsf"){
        }
        return $timeText;
    }
    function getQueue($next_queue, $executor){
        if ($executor == "sge"){
            if (!empty($next_queue)){
                $queueText = "#$ -q $next_queue\\n";
            } else {
                $queueText = "";
            }
        } else if ($executor == "lsf"){
        }
        return $queueText;
    }
    function getNextCluOpt($next_clu_opt, $executor){
        if ($executor == "sge"){
            if (!empty($next_clu_opt)){
                $next_clu_optText = "#$ $next_clu_opt\\n";
            } else {
                $next_clu_optText = "";
            }
        } else if ($executor == "lsf"){
        }
        return $next_clu_optText;
    }
    function getCPU($next_cpu, $executor){
        if ($executor == "sge"){
            if (!empty($next_cpu)){
                $cpuText = "#$ -l slots=$next_cpu\\n";
            } else {
                $cpuText = "";
            }
        } else if ($executor == "lsf"){
        }
        return $cpuText;
    }

    //get all nextflow executor text
    function getExecNextAll($executor, $dolphin_path_real, $next_path_real, $next_inputs, $next_queue, $next_cpu,$next_time,$next_memory,$jobname, $executor_job, $reportOptions, $next_clu_opt, $runType, $profileId, $logName, $initialRunScript, $ownerID) {
        if ($runType == "resumerun"){
            $runType = "-resume";
        } else {
            $runType = "";
        }
        $initialRunCmd = "";
        $igniteCmd = "";
        if ($executor == "local" && $executor_job == 'ignite'){
            $igniteCmd = "-w $dolphin_path_real/work -process.executor ignite";
        }
        if (!empty($initialRunScript)){
            $initialRunCmd = "cd $dolphin_path_real/initialrun && $next_path_real $dolphin_path_real/initialrun/nextflow.nf $igniteCmd $runType $reportOptions > $dolphin_path_real/initialrun/initial.log && ";
        }
        $mainNextCmd = "$initialRunCmd cd $dolphin_path_real && $next_path_real $dolphin_path_real/nextflow.nf $igniteCmd $next_inputs $runType $reportOptions > $dolphin_path_real/$logName";



        //for lsf "bsub -q short -n 1  -W 100 -R rusage[mem=32024]";
        if ($executor == "local"){
            $exec_next_all = "$mainNextCmd ";
        } else if ($executor == "lsf"){
            //convert gb to mb
            settype($next_memory, 'integer');
            $next_memory = $next_memory*1000;
            //-J $jobname
            $jobname = $this->cleanName($jobname);
            $exec_string = "bsub -e $dolphin_path_real/err.log $next_clu_opt -q $next_queue -J $jobname -n $next_cpu -W $next_time -R rusage[mem=$next_memory]";
            $exec_next_all = "$exec_string \\\"$mainNextCmd\\\"";
        } else if ($executor == "sge"){
            $jobnameText = $this->getJobName($jobname, $executor);
            $memoryText = $this->getMemory($next_memory, $executor);
            $timeText = $this->getTime($next_time, $executor);
            $queueText = $this->getQueue($next_queue, $executor);
            $clu_optText = $this->getNextCluOpt($next_clu_opt, $executor);
            $cpuText = $this->getCPU($next_cpu, $executor);
            //-j y ->Specifies whether or not the standard error stream of the job is merged into the standard output stream.
            $sgeRunFile= "printf '#!/bin/bash \\n#$ -j y\\n#$ -V\\n#$ -notify\\n#$ -wd $dolphin_path_real\\n#$ -o $dolphin_path_real/.dolphinnext.log\\n".$jobnameText.$memoryText.$timeText.$queueText.$clu_optText.$cpuText."$mainNextCmd"."'> $dolphin_path_real/.dolphinnext.run";

            $exec_string = "qsub -e $dolphin_path_real/err.log $dolphin_path_real/.dolphinnext.run";
            $exec_next_all = "cd $dolphin_path_real && $sgeRunFile && $exec_string";
        } else if ($executor == "slurm"){
        } else if ($executor == "ignite"){
        }
        return $exec_next_all;
    }

    function getInitialRunConfig($configText,$profileType,$profileId, $initialrun_img, $ownerID){
        $configTextClean = "";
        $configTextLines = explode("\n", $configText);
        //clean container specific lines and insert initialrun image
        for ($i = 0; $i < count($configTextLines); $i++) {
            if (!preg_match("/process.container =/",$configTextLines[$i]) && !preg_match("/singularity.enabled =/",$configTextLines[$i]) && !preg_match("/docker.enabled =/",$configTextLines[$i])){
                $configTextClean .= $configTextLines[$i]."\n";
            }
        }
        list($connect, $ssh_port, $scp_port, $cluDataArr) = $this->getCluAmzData($profileId, $profileType, $ownerID);
        $singu_cache = $cluDataArr[0]["singu_cache"];
        $singuPath = '//$HOME';
        if ($profileType == "amazon"){
            $amzData=$this->getProfileAmazonbyID($profileId, $ownerID);
            $amzDataArr=json_decode($amzData,true);
            $singuPath = $amzDataArr[0]["shared_storage_mnt"]; // /mnt/efs
        }
        if (!empty($singu_cache)){
            $singuPath = $singu_cache;
        }
        preg_match("/shub:\/\/(.*)/", $initialrun_img, $matches);
        $imageName = str_replace("/","-",$matches[1]);
        $image = $singuPath.'/.dolphinnext/singularity/'.$imageName.'.simg';
        $configText = "process.container = '$image'\n"."singularity.enabled = true\n".$configTextClean;
        return $configText;
    }

    function getRenameCmd($dolphin_path_real,$attempt,$initialRunScript){
        $renameLog = "";
        $pathArr = array($dolphin_path_real, "$dolphin_path_real/initialrun");
        foreach ($pathArr as $path):
        if ($path == $dolphin_path_real){
            $renameArr= array("log.txt", "timeline.html", "trace.txt", "dag.html", "report.html", ".nextflow.log", "err.log");
        } else {
            $renameArr= array("initial.log", "timeline.html", "trace.txt", "dag.html", "report.html", ".nextflow.log", "err.log");
        }
        foreach ($renameArr as $item):
        if ($item == "log.txt" || $item == "initial.log"){
            $renameLog .= "cp $path/$item $path/$item.$attempt 2>/dev/null || true && >$path/$item && ";
        } else {
            $renameLog .= "mv $path/$item $path/$item.$attempt 2>/dev/null || true && ";
        }
        endforeach;
        endforeach;
        return $renameLog;
    }

    function initRun($project_pipeline_id, $configText, $nextText, $profileType, $profileId, $amazon_cre_id, $uuid, $initialRunScript, $initialrun_img, $s3configFileDir, $ownerID){
        //if  $amazon_cre_id is defined append the aws credentials into nextflow.config
        if ($amazon_cre_id != "" ){
            $amz_data = json_decode($this->getAmzbyID($amazon_cre_id, $ownerID));
            foreach($amz_data as $d){
                $access = $d->amz_acc_key;
                $d->amz_acc_key = trim($this->amazonDecode($access));
                $secret = $d->amz_suc_key;
                $d->amz_suc_key = trim($this->amazonDecode($secret));
            }
            $access_key = $amz_data[0]->{'amz_acc_key'};
            $secret_key = $amz_data[0]->{'amz_suc_key'};
            $default_region = $amz_data[0]->{'amz_def_reg'};
            $configText.= "aws{\n";
            $configText.= "   accessKey = '$access_key'\n";
            $configText.= "   secretKey = '$secret_key'\n";
            $configText.= "   region = '$default_region'\n";
            $configText.= "}\n";
        }
        //create folders
        if (!file_exists("{$this->run_path}/$uuid/run")) {
            mkdir("{$this->run_path}/$uuid/run", 0755, true);
        }
        $file = fopen("{$this->run_path}/$uuid/run/nextflow.nf", 'w');//creates new file
        fwrite($file, $nextText);
        fclose($file);
        chmod("{$this->run_path}/$uuid/run/nextflow.nf", 0755);
        $file = fopen("{$this->run_path}/$uuid/run/nextflow.config", 'w');//creates new file
        fwrite($file, $configText);
        fclose($file);
        chmod("{$this->run_path}/$uuid/run/nextflow.config", 0755);
        $initialRunText = "";
        $run_path_real = "{$this->run_path}/$uuid/run";
        if (!empty($initialRunScript)){
            $configText = $this->getInitialRunConfig($configText,$profileType,$profileId,$initialrun_img,  $ownerID);

            $this->createDirFile ("{$this->run_path}/$uuid/initialrun", "nextflow.config", 'w', $configText );
            $this->createDirFile ("{$this->run_path}/$uuid/initialrun", "nextflow.nf", 'w', $initialRunScript );
            $initialRunText = "{$this->run_path}/$uuid/initialrun";
        }
        // get outputdir
        $proPipeAll = json_decode($this->getProjectPipelines($project_pipeline_id,"",$ownerID,""));
        $outdir = $proPipeAll[0]->{'output_dir'};
        // get username and hostname for connection
        list($connect, $ssh_port, $scp_port, $cluDataArr) = $this->getCluAmzData($profileId, $profileType, $ownerID);
        $ssh_id = $cluDataArr[0]["ssh_id"];
        //get userpky
        $userpky = "{$this->ssh_path}/{$ownerID}_{$ssh_id}_ssh_pri.pky";
        //check $userpky file exist
        if (!file_exists($userpky)) {
            $this->writeLog($uuid,'Private key is not found!','a','serverlog.txt');
            $this -> updateRunLog($project_pipeline_id, "Error", "", $ownerID);
            $this -> updateRunStatus($project_pipeline_id, "Error", $ownerID);
            die(json_encode('Private key is not found!'));
        }

        if (!file_exists($run_path_real."/nextflow.nf")) {
            $this->writeLog($uuid,'Nextflow file is not found!','a','serverlog.txt');
            $this -> updateRunLog($project_pipeline_id, "Error", "", $ownerID);
            $this -> updateRunStatus($project_pipeline_id, "Error", $ownerID);
            die(json_encode('Nextflow file is not found!'));
        }
        if (!file_exists($run_path_real."/nextflow.config")) {
            $this->writeLog($uuid,'Nextflow config file is not found!','a','serverlog.txt');
            $this -> updateRunLog($project_pipeline_id, "Error", "", $ownerID);
            $this -> updateRunStatus($project_pipeline_id, "Error", $ownerID);
            die(json_encode('Nextflow config file is not found!'));
        }
        $dolphin_path_real = "$outdir/run{$project_pipeline_id}";
        //mkdir and copy nextflow file to run directory in cluster
        $cmd = "ssh {$this->ssh_settings} $ssh_port -i $userpky $connect \"mkdir -p $dolphin_path_real\" > $run_path_real/serverlog.txt 2>&1 && scp -r {$this->ssh_settings} $scp_port -i $userpky $s3configFileDir $initialRunText $run_path_real/nextflow.nf $run_path_real/nextflow.config $connect:$dolphin_path_real >> $run_path_real/serverlog.txt 2>&1";
        $mkdir_copynext_pid =shell_exec($cmd);
        $this->writeLog($uuid,$cmd,'a','serverlog.txt');
        $serverlog = $this->readFile("$run_path_real/serverlog.txt");
        if (preg_match("/cannot create directory(.*)Permission denied/", $serverlog)){
            $this->writeLog($uuid,'ERROR: Run directory could not created. Please make sure your work directory has valid permissions.','a','serverlog.txt');
            $this->updateRunLog($project_pipeline_id, "Error", "", $ownerID);
            $this->updateRunStatus($project_pipeline_id, "Error", $ownerID);
            die(json_encode('ERROR: Run directory could not created. Please make sure your work directory has valid permissions.'));
        }
        $log_array = array('mkdir_copynext_pid' => $mkdir_copynext_pid);
        return $log_array;
    }

    function runCmd($project_pipeline_id, $profileType, $profileId, $log_array, $runType, $uuid, $initialRunScript, $attempt, $initialrun_img, $ownerID)
    {
        //get nextflow executor parameters
        list($outdir, $proPipeCmd, $jobname, $singu_check, $singu_img, $imageCmd, $initImageCmd, $reportOptions) = $this->getNextExecParam($project_pipeline_id,$profileType, $profileId, $initialRunScript, $initialrun_img, $ownerID);
        //get username and hostname and exec info for connection
        list($connect, $next_path, $profileCmd, $executor, $next_time, $next_queue, $next_memory, $next_cpu, $next_clu_opt, $executor_job, $ssh_id, $ssh_port)=$this->getNextConnectExec($profileId,$ownerID, $profileType);
        //get nextflow input parameters
        $next_inputs = $this->getNextInputs($executor, $project_pipeline_id, $outdir, $ownerID);
        //get cmd before run
        $preCmd = $this->getPreCmd ($profileType,$profileCmd,$proPipeCmd, $imageCmd, $initImageCmd);
        //eg. /project/umw_biocore/bin
        $next_path_real = $this->getNextPathReal($next_path);
        //get userpky
        $userpky = "{$this->ssh_path}/{$ownerID}_{$ssh_id}_ssh_pri.pky";
        if (!file_exists($userpky)) {
            $this -> writeLog($uuid,'Private key is not found!','a','serverlog.txt');
            $this -> updateRunLog($project_pipeline_id, "Error", "", $ownerID);
            $this -> updateRunStatus($project_pipeline_id, "Error", $ownerID);
            die(json_encode('Private key is not found!'));
        }
        $run_path_real = "{$this->run_path}/$uuid/run";
        $dolphin_path_real = "$outdir/run{$project_pipeline_id}";
        //get command for renaming previous log file
        $renameLog = $this->getRenameCmd($dolphin_path_real, $attempt, $initialRunScript);
        //check if files are exist
        $next_exist_cmd= "ssh {$this->ssh_settings} $ssh_port -i $userpky $connect test  -f \"$dolphin_path_real/nextflow.nf\"  && echo \"Nextflow file exists\" || echo \"Nextflow file not exists\" 2>&1 & echo $! &";
        $next_exist = shell_exec($next_exist_cmd);
        $this->writeLog($uuid,$next_exist_cmd,'a','serverlog.txt');
        $serverlog = $this->readFile("$run_path_real/serverlog.txt");
        if (preg_match("/cannot create directory(.*)Permission denied/", $serverlog)){
            $this->writeLog($uuid,'ERROR: Run directory could not created. Please make sure your work directory has valid permissions.','a','serverlog.txt');
            $this->updateRunLog($project_pipeline_id, "Error", "", $ownerID);
            $this->updateRunStatus($project_pipeline_id, "Error", $ownerID);
            die(json_encode('ERROR: Run directory could not created. Please make sure your work directory has valid permissions.'));
        }
        preg_match("/(.*)Nextflow file(.*)exists(.*)/", $next_exist, $matches);
        $log_array['next_exist'] = $next_exist;
        if ($matches[2] == " ") {
            $exec_next_all = $this->getExecNextAll($executor, $dolphin_path_real, $next_path_real, $next_inputs, $next_queue,$next_cpu,$next_time,$next_memory, $jobname, $executor_job, $reportOptions, $next_clu_opt, $runType, $profileId, "log.txt", $initialRunScript, $ownerID);
            $cmd="ssh {$this->ssh_settings} $ssh_port -i $userpky $connect \"$renameLog $preCmd $exec_next_all\" >> $run_path_real/serverlog.txt 2>&1 & echo $! &";
            $next_submit_pid= shell_exec($cmd); //"Job <203477> is submitted to queue <long>.\n"
            $this->writeLog($uuid,$cmd,'a','serverlog.txt');
            if (!$next_submit_pid) {
                $this->writeLog($uuid,'ERROR: Connection failed! Please check your connection profile or internet connection','a','serverlog.txt');
                $this->updateRunLog($project_pipeline_id, "Error", "", $ownerID);
                $this->updateRunStatus($project_pipeline_id, "Error", $ownerID);
                die(json_encode('ERROR: Connection failed. Please check your connection profile or internet connection'));
            }
            $log_array['next_submit_pid'] = $next_submit_pid;
            $this->updateRunLog($project_pipeline_id, "Waiting", "", $ownerID);
            $this->updateRunStatus($project_pipeline_id, "Waiting", $ownerID);
            return json_encode($log_array);
        } else if ($matches[2] == " not "){
            for( $i= 0 ; $i < 3 ; $i++ ){
                $this->writeLog($uuid,'WARN: Run directory is not found in run environment.','a','serverlog.txt');
                sleep(3);
                $next_exist = shell_exec($next_exist_cmd);
                preg_match("/(.*)Nextflow file(.*)exists(.*)/", $next_exist, $matches);
                $log_array['next_exist'] = $next_exist;
                if ($matches[2] == " ") {
                    $next_submit_pid= shell_exec($cmd); //"Job <203477> is submitted to queue <long>.\n"
                    if (!$next_submit_pid) {
                        $this->writeLog($uuid,'ERROR: Connection failed. Please check your connection profile or internet connection','a','serverlog.txt');
                        $this->updateRunLog($project_pipeline_id, "Error", "", $ownerID);
                        $this->updateRunStatus($project_pipeline_id, "Error", $ownerID);
                        die(json_encode('ERROR: Connection failed. Please check your connection profile or internet connection'));
                    }
                    $log_array['next_submit_pid'] = $next_submit_pid;
                    $this->updateRunLog($project_pipeline_id, "Waiting", "", $ownerID);
                    $this->updateRunStatus($project_pipeline_id, "Waiting", $ownerID);
                    return json_encode($log_array);
                }
            }
            $this -> writeLog($uuid,'ERROR: Connection failed. Please check your connection profile or internet connection','a','serverlog.txt');
            $this -> updateRunLog($project_pipeline_id, "Error", "", $ownerID);
            $this -> updateRunStatus($project_pipeline_id, "Error", $ownerID);
            die(json_encode('ERROR: Connection failed. Please check your connection profile or internet connection'));
        }
    }

    public function updateRunAttemptLog($status, $project_pipeline_id, $uuid, $ownerID){
        //check if $project_pipeline_id already exits un run table
        $checkRun = $this->getRun($project_pipeline_id,$ownerID);
        $checkarray = json_decode($checkRun,true);
        $attempt = isset($checkarray[0]["attempt"]) ? $checkarray[0]["attempt"] : "";
        settype($attempt, 'integer');
        if (empty($attempt)){
            $attempt = 0;
        }
        $attempt += 1;
        if (isset($checkarray[0])) {
            $this->updateRunAttempt($project_pipeline_id, $attempt, $ownerID);
            $this->updateRunStatus($project_pipeline_id, $status, $ownerID);
        } else {
            $this->insertRun($project_pipeline_id, $status, "1", $ownerID);
        }
        $data = $this->insertRunLog($project_pipeline_id, $uuid, $status, $ownerID);
    }

    public function generateKeys($ownerID) {
        $cmd = "rm -rf {$this->ssh_path}/.tmp$ownerID && mkdir -p {$this->ssh_path}/.tmp$ownerID && cd {$this->ssh_path}/.tmp$ownerID && ssh-keygen -C @dolphinnext -f tkey -t rsa -N '' > logTemp.txt 2>&1 & echo $! &";
        $log_array = $this->runCommand ($cmd, 'create_key', '');
        if (preg_match("/([0-9]+)(.*)/", $log_array['create_key'])){
            $log_array['create_key_status'] = "active";
        }else {
            $log_array['create_key_status'] = "error";
        }
        return json_encode($log_array);
    }
    public function readGenerateKeys($ownerID) {
        $keyPubPath ="{$this->ssh_path}/.tmp$ownerID/tkey.pub";
        $keyPriPath ="{$this->ssh_path}/.tmp$ownerID/tkey";
        $keyPub = $this->readFile($keyPubPath);
        $keyPri = $this->readFile($keyPriPath);
        $log_array = array('$keyPub' => $keyPub);
        $log_array['$keyPri'] = $keyPri;
        //remove the directory after reading files.
        $cmd = "rm -rf {$this->ssh_path}/.tmp$ownerID 2>&1 & echo $! &";
        $log_remove = $this->runCommand ($cmd, 'remove_key', '');
        return json_encode($log_array);
    }
    function insertKey($id, $key, $type, $ownerID){
        mkdir("{$this->ssh_path}", 0700, true);
        if ($type == 'clu'){
            $file = fopen("{$this->ssh_path}/{$ownerID}_{$id}.pky", 'w');//creates new file
            fwrite($file, $key);
            fclose($file);
            chmod("{$this->ssh_path}/{$ownerID}_{$id}.pky", 0600);
        } else if ($type == 'amz_pri'){
            $file = fopen("{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky", 'w');//creates new file
            fwrite($file, $key);
            fclose($file);
            chmod("{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky", 0600);
        } else if ($type == 'amz_pub'){
            $file = fopen("{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky", 'w');//creates new file
            fwrite($file, $key);
            fclose($file);
            chmod("{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky", 0600);
        } else if ($type == 'ssh_pub'){
            $file = fopen("{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky", 'w');//creates new file
            fwrite($file, $key);
            fclose($file);
            chmod("{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky", 0600);
        } else if ($type == 'ssh_pri'){
            $file = fopen("{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky", 'w');//creates new file
            fwrite($file, $key);
            fclose($file);
            chmod("{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky", 0600);
        }
    }
    function readKey($id, $type, $ownerID)
    {
        if ($type == 'clu'){
            $filename = "{$this->ssh_path}/{$ownerID}_{$id}.pky";
        } else if ($type == 'amz_pub' || $type == 'amz_pri'){
            $filename = "{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky";
        } else if ($type == 'ssh_pub' || $type == 'ssh_pri'){
            $filename = "{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky";
        }
        $handle = fopen($filename, 'r');//creates new file
        $content = fread($handle, filesize($filename));
        fclose($handle);
        return $content;
    }
    function delKey($id, $type, $ownerID){
        if ($type == 'clu'){
            $filename = "{$this->ssh_path}/{$ownerID}_{$id}.pky";
        } else if ($type == 'amz_pub' || $type == 'amz_pri'){
            $filename = "{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky";
        } else if ($type == 'ssh_pri' || $type == 'ssh_pub'){
            $filename = "{$this->ssh_path}/{$ownerID}_{$id}_{$type}.pky";
        }
        unlink($filename);
    }

    function amazonEncode($a_key){
        $encrypted_string=openssl_encrypt($a_key,"AES-128-ECB",$this->amazon);
        return $encrypted_string;
    }
    function amazonDecode($a_key){
        $decrypted_string=openssl_decrypt($a_key,"AES-128-ECB",$this->amazon);
        return $decrypted_string;
    }
    function keyAsterisk($key){
        if (strlen($key) >3){
            $key=str_repeat('*', strlen($key) - 4) . substr($key, -4);
        } 
        return $key;
    }
    function startProAmazon($id,$ownerID, $username){
        $profileName = "{$username}_{$id}";
        $data = json_decode($this->getProfileAmazonbyID($id, $ownerID));
        $amazon_cre_id = $data[0]->{'amazon_cre_id'};
        $amz_data = json_decode($this->getAmzbyID($amazon_cre_id, $ownerID));
        foreach($amz_data as $d){
            $access = $d->amz_acc_key;
            $d->amz_acc_key = trim($this->amazonDecode($access));
            $secret = $d->amz_suc_key;
            $d->amz_suc_key = trim($this->amazonDecode($secret));
        }
        $access_key = $amz_data[0]->{'amz_acc_key'};
        $secret_key = $amz_data[0]->{'amz_suc_key'};
        $default_region = $amz_data[0]->{'amz_def_reg'};
        $name = $data[0]->{'name'};
        $ssh_id = $data[0]->{'ssh_id'};
        $username = $data[0]->{'username'};
        $image_id = $data[0]->{'image_id'};
        $instance_type = $data[0]->{'instance_type'};
        $subnet_id = $data[0]->{'subnet_id'};
        $security_group = $data[0]->{'security_group'};
        $shared_storage_id = $data[0]->{'shared_storage_id'};
        $shared_storage_mnt = $data[0]->{'shared_storage_mnt'};
        $keyFile = "{$this->ssh_path}/{$ownerID}_{$ssh_id}_ssh_pub.pky";
        $nodes = $data[0]->{'nodes'};
        settype($nodes, "integer");
        $autoscale_check = $data[0]->{'autoscale_check'};
        $autoscale_maxIns = $data[0]->{'autoscale_maxIns'};
        //        $autoscale_minIns = $nodes;
        $text= "cloud { \n";
        $text.= "   userName = '$username'\n";
        $text.= "   imageId = '$image_id'\n";
        $text.= "   instanceType = '$instance_type'\n";
        $text.= "   securityGroup = '$security_group'\n"; 
        $text.= "   subnetId = '$subnet_id'\n"; 

        if (!empty($shared_storage_id)){ $text.= "   sharedStorageId = '$shared_storage_id'\n"; }
        if (!empty($shared_storage_mnt)){ $text.= "   sharedStorageMount = '$shared_storage_mnt'\n"; }
        $text.= "   keyFile = '$keyFile'\n";
        if ($autoscale_check == "true"){
            $text.= "   autoscale {\n";
            $text.= "       enabled = true \n";
            $text.= "       terminateWhenIdle = true\n";
            //            if (!empty($autoscale_minIns)){
            //                $text.= "       minInstances = $autoscale_minIns\n";
            //            }
            if (!empty($autoscale_maxIns)){
                $text.= "       maxInstances = $autoscale_maxIns\n";
            }
            $text.= "   }\n";
        }
        $text.= "}\n";
        $text.= "aws{\n";
        $text.= "   accessKey = '$access_key'\n";
        $text.= "   secretKey = '$secret_key'\n";
        $text.= "   region = '$default_region'\n";
        $text.= "}\n";
        $this->createDirFile ("{$this->amz_path}/pro_{$profileName}", "nextflow.config", 'w', $text );
        $nodeText = "";
        if ($nodes >1){
            $nodeText = "-c $nodes";
        }
        $nextVer = $this->next_ver;
        $nextVerText = "";
        if (!empty($nextVer)){
            $nextVerText = "export NXF_VER=$nextVer &&";
        }
        //start amazon cluster
        $cmd = "cd {$this->amz_path}/pro_{$profileName} && $nextVerText yes | nextflow cloud create $profileName $nodeText > logAmzStart.txt 2>&1 & echo $! &";
        $log_array = $this->runCommand ($cmd, 'start_cloud', '');
        $log_array['start_cloud_cmd'] = $cmd;
        //xxx save pid of nextflow cloud create cluster job
        if (preg_match("/([0-9]+)(.*)/", $log_array['start_cloud'])){
            $this->updateAmazonProStatus($id, "waiting", $ownerID);
        }else {
            $this->updateAmazonProStatus($id, "terminated", $ownerID);
        }
        return json_encode($log_array);
    }

    function stopProAmazon($id,$ownerID,$username){
        $profileName = "{$username}_{$id}";
        $nextVer = $this->next_ver;
        $nextVerText = "";
        if (!empty($nextVer)){
            $nextVerText = "export NXF_VER=$nextVer &&";
        }
        //stop amazon cluster
        $cmd = "cd {$this->amz_path}/pro_{$profileName} && $nextVerText yes | nextflow cloud shutdown $profileName > logAmzStop.txt 2>&1 & echo $! &";
        $log_array = $this->runCommand ($cmd, 'stop_cloud', '');
        return json_encode($log_array);
    }
    function triggerShutdown ($id,$ownerID, $type){
        $amzDataJS=$this->getProfileAmazonbyID($id, $ownerID);
        $amzData=json_decode($amzDataJS,true)[0];
        $username = $amzData["username"];
        if (!empty($username)){
            $usernameCl = str_replace(".","__",$username);   
        }
        $autoshutdown_date = $amzData["autoshutdown_date"];
        $autoshutdown_active = $amzData["autoshutdown_active"];
        $autoshutdown_check = $amzData["autoshutdown_check"];
        // get list of active runs using this profile 
        $activeRun=json_decode($this->getActiveRunbyProID($id, $ownerID),true);
        if (count($activeRun) > 0){ return "Active run is found"; }
        //if process comes to this checkpoint it has to be activated
        if ($autoshutdown_check == "true" && $autoshutdown_active == "true"){
            //if timer not set then set timer
            if (empty($autoshutdown_date)){
                $autoshutdown_date = strtotime("+10 minutes");
                $mysqltime = date ("Y-m-d H:i:s", $autoshutdown_date);
                $this->updateAmzShutdownDate($id, $mysqltime, $ownerID);
                return "Timer set to: $mysqltime";
            } else {
                //if timer is set the check if time elapsed -> stopProAmazon
                $expected_date = strtotime($autoshutdown_date);
                $remaining = $expected_date - time();
                if ($remaining < 1){
                    $stopProAmazon = $this->stopProAmazon($id,$ownerID,$usernameCl);
                    //track termination of instance
                    if ($type == "slow"){
                        for ($i = 0; $i < 10; $i++) {
                            $runAmzCloudCheck = $this->runAmazonCloudCheck($id,$ownerID, $usernameCl);
                            sleep(15);
                            $checkAmazonStatus = $this->checkAmazonStatus($id,$ownerID,$usernameCl);
                            $newStatus = json_decode($checkAmazonStatus)->{'status'};
                            if ($newStatus == "terminated"){
                                break;
                            }
                        }
                    }
                    return json_encode($stopProAmazon);
                } else {
                    return "$remaining seconds left to shutdown.";
                }
            }
        } else {
            return "Shutdown feature has not been activated.";
        }
    }

    function checkAmzStopLog($id,$ownerID,$username){
        $profileName = "{$username}_{$id}";
        //read logAmzStop.txt
        $logPath ="{$this->amz_path}/pro_{$profileName}/logAmzStop.txt";
        $logAmzStop = $this->readFile($logPath);
        $log_array = array('logAmzStop' => $logAmzStop);
        return json_encode($log_array);
    }
    //read both start and list files
    function readAmzCloudListStart($id,$username){
        $profileName = "{$username}_{$id}";
        //read logAmzCloudList.txt
        $logPath ="{$this->amz_path}/pro_{$profileName}/logAmzCloudList.txt";
        $logAmzCloudList = $this->readFile($logPath);
        $log_array = array('logAmzCloudList' => $logAmzCloudList);
        //read logAmzStart.txt
        $logPathStart ="{$this->amz_path}/pro_{$profileName}/logAmzStart.txt";
        $logAmzStart = $this->readFile($logPathStart);
        $log_array['logAmzStart'] = $logAmzStart;
        return $log_array;
    }
    //available status: waiting, initiated, terminated, running
    public function checkAmazonStatus($id,$ownerID,$username) {
        $profileName = "{$username}_{$id}";
        //check status
        $amzStat = json_decode($this->getAmazonStatus($id,$ownerID));
        $status = $amzStat[0]->{'status'};
        $node_status = $amzStat[0]->{'node_status'};
        if ($status == "waiting"){
            //check cloud list
            $log_array = $this->readAmzCloudListStart($id,$username);
            if (preg_match("/running/", $log_array['logAmzCloudList'])){
                $this->updateAmazonProStatus($id, "initiated", $ownerID);
                $log_array['status'] = "initiated";
                return json_encode($log_array);
            } else if (!preg_match("/STATUS/", $log_array['logAmzCloudList']) && (preg_match("/Missing/i", $log_array['logAmzCloudList']) || preg_match("/denied/i", $log_array['logAmzCloudList']) || preg_match("/ERROR/i", $log_array['logAmzCloudList']))){
                $this->updateAmazonProStatus($id, "terminated", $ownerID);
                $log_array['status'] = "terminated";
                return json_encode($log_array);
            }else if (preg_match("/Missing/i", $log_array['logAmzStart']) || preg_match("/denied/i", $log_array['logAmzStart']) || (preg_match("/ERROR/i", $log_array['logAmzStart']) && !preg_match("/WARN: One or more errors/i", $log_array['logAmzStart'])) || preg_match("/couldn't/i", $log_array['logAmzStart'])  || preg_match("/help/i", $log_array['logAmzStart']) || preg_match("/wrong/i", $log_array['logAmzStart'])){
                $this->updateAmazonProStatus($id, "terminated", $ownerID);
                $log_array['status'] = "terminated";
                return json_encode($log_array);
            }else {
                //error
                $log_array['status'] = "waiting";
                return json_encode($log_array);
            }
        } else if ($status == "initiated"){
            //check cloud list
            $log_array = $this->readAmzCloudListStart($id,$username);
            if (preg_match("/running/",$log_array['logAmzCloudList']) && preg_match("/STATUS/",$log_array['logAmzCloudList'])){
                //read logAmzStart.txt
                $amzStartPath ="{$this->amz_path}/pro_{$profileName}/logAmzStart.txt";
                $amzStartLog = $this->readFile($amzStartPath);
                $log_array['$amzStartLog'] = $amzStartLog;
                if (preg_match("/ssh -i(.*)/",$amzStartLog)){
                    preg_match("/ssh -i <(.*)> (.*)/",$amzStartLog, $match);
                    $sshText = $match[2];
                    $log_array['sshText'] = $sshText;
                    $log_array['status'] = "running";
                    $this->updateAmazonProStatus($id, "running", $ownerID);
                    $this->updateAmazonProSSH($id, $sshText, $ownerID);
                    //parse child nodes
                    $cluData=$this->getProfileAmazonbyID($id, $ownerID);
                    $cluDataArr=json_decode($cluData,true);
                    $numNodes = $cluDataArr[0]["nodes"];
                    settype($numNodes, "integer");
                    $username = $cluDataArr[0]["username"];
                    if ($numNodes >1){
                        $log_array['nodes'] = $numNodes;
                        if (preg_match("/.*Launching worker node.*/",$amzStartLog)){
                            preg_match("/.*Launching worker node.*ready\.(.*)Launching master node --/s",$amzStartLog, $matchNodes);
                            if (!empty($matchNodes[1])){
                                preg_match_all("/[ ]+[^ ]+[ ]+(.*\.com)\n.*/sU",$matchNodes[1], $matchNodesAll);
                                $log_array['childNodes'] = $matchNodesAll[1];
                            }
                        }
                    }
                    return json_encode($log_array);
                } else {
                    $log_array['status'] = "initiated";
                    return json_encode($log_array);
                }
            } else if (!preg_match("/running/",$log_array['logAmzCloudList']) && preg_match("/STATUS/",$log_array['logAmzCloudList'])){
                $this->updateAmazonProStatus($id, "terminated", $ownerID);
                $log_array['status'] = "terminated";
                return json_encode($log_array);
            } else {
                $log_array['status'] = "retry";
                return json_encode($log_array);
            }
        } else if ($status == "running"){
            //check cloud list
            $log_array = $this->readAmzCloudListStart($id,$username);
            if (preg_match("/running/",$log_array['logAmzCloudList']) && preg_match("/STATUS/",$log_array['logAmzCloudList'])){
                $log_array['status'] = "running";
                $sshTextArr = json_decode($this->getAmazonProSSH($id, $ownerID));
                $sshText = $sshTextArr[0]->{'ssh'};
                $log_array['sshText'] = $sshText;
                return json_encode($log_array);
            } else if (!preg_match("/running/",$log_array['logAmzCloudList']) && preg_match("/STATUS/",$log_array['logAmzCloudList'])){
                $this->updateAmazonProStatus($id, "terminated", $ownerID);
                $log_array['status'] = "terminated";
                return json_encode($log_array);
            } else {
                $log_array['status'] = "retry";
                return json_encode($log_array);
            }
        }
        else if ($status == "terminated"){
            $log_array = $this->readAmzCloudListStart($id,$username);
            $log_array['status'] = "terminated";
            return json_encode($log_array);
        } else if ($status == "" ){
            $log_array = array('status' => 'inactive');
            return json_encode($log_array);
        }else if ($status == "inactive"){
            $log_array = array('status' => 'inactive');
            return json_encode($log_array);
        }
    }

    //check cloud list
    public function runAmazonCloudCheck($id,$ownerID,$username){
        $profileName = "{$username}_{$id}";
        $nextVer = $this->next_ver;
        $nextVerText = "";
        if (!empty($nextVer)){
            $nextVerText = "export NXF_VER=$nextVer &&";
        }
        $cmd = "cd {$this->amz_path}/pro_$profileName && rm -f logAmzCloudList.txt && $nextVerText nextflow cloud list $profileName >> logAmzCloudList.txt 2>&1 & echo $! &";
        $log_array = $this->runCommand ($cmd, 'cloudlist', '');
        return json_encode($log_array);
    }

    //this function is not finalized.
    //    public function tagAmazonInst($id,$ownerID){
    //        $amzDataJS=$this->getProfileAmazonbyID($id, $ownerID);
    //        $amzData=json_decode($amzDataJS,true)[0];
    //        $username = $amzData["username"];
    //        if (!empty($username)){
    //            $usernameCl = str_replace(".","__",$username);   
    //        }
    //        $runAmzCloudCheck = $this->runAmazonCloudCheck($id,$ownerID,$usernameCl);
    //        sleep(15);
    //        $checkAmazonStatus = $this->checkAmazonStatus($id,$ownerID,$usernameCl);
    //        $status = json_decode($checkAmazonStatus)->{'status'};
    //        $logAmzCloudList = json_decode($checkAmazonStatus)->{'logAmzCloudList'};
    //        error_log($logAmzCloudList);
    //        //INSTANCE ID         ADDRESS                                    STATUS  ROLE  
    //        //i-0c9b9bca326881c15 ec2-54-234-221-109.compute-1.amazonaws.com running worker
    //        //i-0bdc64c4196e1f956 ec2-3-84-86-146.compute-1.amazonaws.com    running master
    //        $lines = explode("\n", $logAmzCloudList);
    //        $inst = array();
    //        for ($i = 1; $i < count($lines); $i++) {
    //            $obj = new stdClass();
    //            $currentline = explode(" ", $lines[$i]);
    //            if (!empty($currentline)){
    //                if (preg_match("/i-/",$currentline[0])){
    //                    $inst[] = trim($currentline[0]);
    //                    "export AWS_ACCESS_KEY_ID= && export AWS_SECRET_ACCESS_KEY= && aws ec2 create-tags --resources $currentline[0] --tags Key=username,Value=test && unset AWS_ACCESS_KEY_ID && unset AWS_SECRET_ACCESS_KEY";
    //                }
    //            }
    //        }
    //        error_log("inst:".print_r($inst, TRUE));
    //        
    //        return json_encode($logAmzCloudList);
    //    }

    public function getLastRunData($project_pipeline_id, $ownerID){
        $sql = "SELECT DISTINCT pp.id, pp.output_dir, pp.profile, pp.last_run_uuid, pp.date_modified, pp.owner_id, r.run_status
            FROM project_pipeline pp
            INNER JOIN run_log r
            WHERE pp.last_run_uuid = r.run_log_uuid AND pp.deleted=0 AND pp.id='$project_pipeline_id' AND pp.owner_id='$ownerID'";
        return self::queryTable($sql);
    }

    public function updateProPipeStatus ($project_pipeline_id, $loadtype, $ownerID){
        // get active runs //Available Run_status States: NextErr,NextSuc,NextRun,Error,Waiting,init,Terminated, Aborted
        // if runStatus equal to  Terminated, NextSuc, Error,NextErr, it means run already stopped. 
        $out = array();
        $duration = ""; //run duration
        $newRunStatus = "";
        $saveNextLog = "";
        $runDataJS = $this->getLastRunData($project_pipeline_id,$ownerID);
        if (empty(json_decode($runDataJS,true))){
            //old run folder format may exist (runID)
            $runStat = json_decode($this -> getRunStatus($project_pipeline_id, $ownerID));
            $runStatus = $runStat[0]->{"run_status"};
            $last_run_uuid = "run".$project_pipeline_id;
            $proPipeAll = json_decode($this->getProjectPipelines($project_pipeline_id,"",$ownerID,""));
            $amazon_cre_id = $proPipeAll[0]->{'amazon_cre_id'};
            $output_dir = $proPipeAll[0]->{'output_dir'};
            $profile = $proPipeAll[0]->{'profile'};
            $subRunLogDir = "";
        } else if (!empty(json_decode($runDataJS,true))){
            // latest last_uuid format exist
            $runData = json_decode($runDataJS,true)[0];
            $runStatus = $runData["run_status"];
            $last_run_uuid = $runData["last_run_uuid"];
            $output_dir = $runData["output_dir"];
            $profile = $runData["profile"];
            $subRunLogDir = "run";
        }
        $profileAr = explode("-", $profile);
        $profileType = $profileAr[0];
        $profileId = $profileAr[1];
        if (!empty($last_run_uuid)){
            $run_path_real = "$output_dir/run{$project_pipeline_id}";
            $down_file_list=array("log.txt",".nextflow.log","report.html", "timeline.html", "trace.txt","dag.html","err.log", "initialrun/initial.log");
            foreach ($down_file_list as &$value) {
                $value = $run_path_real."/".$value;
            }
            unset($value);
            //wait for the downloading logs
            if ($loadtype == "slow"){
                $saveNextLog = $this -> saveNextflowLog($down_file_list, $last_run_uuid, "run", $profileType, $profileId, $project_pipeline_id, $ownerID);
                sleep(5);
                $out["saveNextLog"] = $saveNextLog;
            }
            $serverLog = json_decode($this -> getFileContent($last_run_uuid, "run/serverlog.txt", $ownerID));
            
            $errorLog = json_decode($this -> getFileContent($last_run_uuid, "$subRunLogDir/err.log", $ownerID));
            $initialLog = json_decode($this -> getFileContent($last_run_uuid, "$subRunLogDir/initial.log", $ownerID));
            $nextflowLog = json_decode($this -> getFileContent($last_run_uuid, "$subRunLogDir/log.txt", $ownerID));
            $serverLog = isset($serverLog) ? trim($serverLog) : "";
            $errorLog = isset($errorLog) ? trim($errorLog) : "";
            $initialLog = isset($initialLog) ? trim($initialLog) : "";
            $nextflowLog = isset($nextflowLog) ? trim($nextflowLog) : "";
            if (!empty($errorLog)) { $serverLog = $serverLog . "\n" . $errorLog; }
            if (!empty($initialLog)) { $nextflowLog = $initialLog . "\n" . $nextflowLog; }
            $out["serverLog"] = $serverLog;
            $out["nextflowLog"] = $nextflowLog;

            if ($runStatus === "Terminated" || $runStatus === "NextSuc" || $runStatus === "Error" || $runStatus === "NextErr") {
                // when run hasn't finished yet and connection is down
            } else if ($loadtype == "slow" && $saveNextLog == "logNotFound" && ($runStatus != "Waiting" && $runStatus !== "init")) {
                //log file might be deleted or couldn't read the log file
                $newRunStatus = "Aborted";
            } else if (preg_match("/error/i",$serverLog) || preg_match("/command not found/i",$serverLog)) {
                $newRunStatus = "Error";
                // otherwise parse nextflow file to get status
            } else if (!empty($nextflowLog)){
                if (preg_match("/N E X T F L O W/",$nextflowLog)){
                    //run completed with error
                    if (preg_match("/##Success: failed/",$nextflowLog)){
                        preg_match("/##Duration:(.*)\n/",$nextflowLog, $matchDur);
                        $duration = !empty($matchDur[1]) ? $matchDur[1] : "";
                        $newRunStatus = "NextErr";
                        //run completed with success
                    } else if (preg_match("/##Success: OK/",$nextflowLog)){
                        preg_match("/##Duration:(.*)/",$nextflowLog, $matchDur);
                        $duration = !empty($matchDur[1]) ? $matchDur[1] : "";
                        $newRunStatus = "NextSuc";
                        // run error
                        //"WARN: Failed to publish file" gives error
                        //|| preg_match("/failed/i",$nextflowLog) removed 
                    } else if (preg_match("/error/i",$nextflowLog)){
                        $confirmErr=true;
                        if (preg_match("/-- Execution is retried/i",$nextflowLog)){
                            //if only process retried, status shouldn't set as error.
                            $confirmErr = false;
                            $txt = trim($nextflowLog);
                            $lines = explode("\n", $txt);
                            for ($i = 0; $i < count($lines); $i++) {
                                if (preg_match("/error/i",$lines[$i]) && !preg_match("/-- Execution is retried/i",$lines[$i])){
                                    $confirmErr = true;
                                    break;
                                }
                            }
                        }
                        if ($confirmErr == true){
                            $newRunStatus = "NextErr";
                        } else {
                            $newRunStatus = "NextRun";
                        }
                    } else {
                        //update status as running  
                        $newRunStatus = "NextRun";
                    }
                    //Nextflow log file exist but /N E X T F L O W/ not printed yet
                } else {
                    $newRunStatus = "Waiting";
                }
            } else{
                //"Nextflow log is not exist yet."
                $newRunStatus = "Waiting";
            }
            if (!empty($newRunStatus)){
                $setStatus = $this -> updateRunStatus($project_pipeline_id, $newRunStatus, $ownerID);
                $setLog = $this -> updateRunLog($project_pipeline_id, $newRunStatus, $duration, $ownerID); 
                $out["runStatus"] = $newRunStatus;
                if (($newRunStatus == "NextErr" || $newRunStatus == "NextSuc" || $newRunStatus == "Error") && $profileType == "amazon"){
                    $triggerShutdown = $this -> triggerShutdown($profileId,$ownerID, "fast");
                }
            } else {
                $out["runStatus"] = $runStatus;
            }
        }
        return json_encode($out);
    }



    //------------- SideBar Funcs --------
    public function getParentSideBar($ownerID){
        if ($ownerID != ''){
            $userRoleArr = json_decode($this->getUserRole($ownerID));
            $userRole = $userRoleArr[0]->{'role'};
            if ($userRole == "admin"){
                $sql= "SELECT DISTINCT pg.group_name name, pg.id, pg.perms, pg.group_id
                  FROM process_group pg ";
                return self::queryTable($sql);
            }
            $sql= "SELECT DISTINCT pg.group_name name, pg.id, pg.perms, pg.group_id
                FROM process_group pg
                LEFT JOIN user_group ug ON  pg.group_id=ug.g_id
                where pg.owner_id='$ownerID' OR pg.perms = 63 OR (ug.u_id ='$ownerID' and pg.perms = 15) ";
        } else {
            $sql= "SELECT DISTINCT group_name name, id FROM process_group where perms = 63";
        }
        return self::queryTable($sql);
    }
    public function getParentSideBarPipeline($ownerID){
        if ($ownerID != ''){
            $userRoleArr = json_decode($this->getUserRole($ownerID));
            $userRole = $userRoleArr[0]->{'role'};
            if ($userRole == "admin"){
                $sql= "SELECT DISTINCT pg.group_name name, pg.id, pg.perms, pg.group_id
                  FROM pipeline_group pg ";
                return self::queryTable($sql);
            }
            $sql= "SELECT DISTINCT pg.group_name name, pg.id, pg.perms, pg.group_id
                FROM pipeline_group pg
                LEFT JOIN user_group ug ON  pg.group_id=ug.g_id
                where pg.owner_id='$ownerID' OR pg.perms = 63 OR (ug.u_id ='$ownerID' and pg.perms = 15) ";
        } else {
            $sql= "SELECT DISTINCT group_name name, id FROM pipeline_group where perms = 63";
        }
        return self::queryTable($sql);
    }

    public function getPipelineSideBar($ownerID){
        if ($ownerID != ''){
            $userRoleArr = json_decode($this->getUserRole($ownerID));
            $userRole = $userRoleArr[0]->{'role'};
            if ($userRole == "admin"){
                $where = " WHERE p.deleted = 0";
            } else {
                $where = " WHERE p.deleted = 0 AND (p.owner_id='$ownerID' OR (p.perms = 63 AND p.pin = 'true') OR (ug.u_id ='$ownerID' and p.perms = 15)) ";
            }
            $sql= "SELECT DISTINCT pip.id, pip.name, pip.perms, pip.group_id, pip.pin
                FROM biocorepipe_save pip
                LEFT JOIN user_group ug ON  pip.group_id=ug.g_id
                INNER JOIN (
                  SELECT p.pipeline_gid, MAX(p.rev_id) rev_id
                  FROM biocorepipe_save p
                  LEFT JOIN user_group ug ON p.group_id=ug.g_id
                  $where
                  GROUP BY p.pipeline_gid
                ) b ON pip.rev_id = b.rev_id AND pip.deleted = 0 AND pip.pipeline_gid=b.pipeline_gid";

        } else {
            $sql= "SELECT DISTINCT pip.id, pip.name, pip.perms, pip.group_id, pip.pin
                FROM biocorepipe_save pip
                INNER JOIN (
                  SELECT p.pipeline_gid, MAX(p.rev_id) rev_id
                  FROM biocorepipe_save p
                  WHERE p.perms = 63 AND p.deleted=0
                  GROUP BY p.pipeline_gid
                ) b ON pip.rev_id = b.rev_id AND pip.pipeline_gid=b.pipeline_gid AND pip.pin = 'true' AND pip.deleted = 0";
        }
        return self::queryTable($sql);
    }

    public function getSubMenuFromSideBar($parent, $ownerID){
        if ($ownerID != ''){
            $userRoleArr = json_decode($this->getUserRole($ownerID));
            $userRole = $userRoleArr[0]->{'role'};
            if ($userRole == "admin"){
                $sql="SELECT DISTINCT p.id, p.name, p.perms, p.group_id, p.owner_id, p.publish
                  FROM process p
                  INNER JOIN process_group pg
                  ON p.process_group_id = pg.id and pg.group_name='$parent'
                  INNER JOIN (
                    SELECT pr.process_gid, MAX(pr.rev_id) rev_id
                    FROM process pr
                    WHERE pr.deleted=0
                    GROUP BY pr.process_gid
                  ) b ON p.rev_id = b.rev_id AND p.process_gid=b.process_gid AND p.deleted=0 ";
                return self::queryTable($sql);
            }
            $where_pg = "p.deleted=0 AND (pg.owner_id='$ownerID' OR pg.perms = 63 OR (ug.u_id ='$ownerID' and pg.perms = 15))";
            $where_pr = "pr.deleted=0 AND (pr.owner_id='$ownerID' OR pr.perms = 63 OR (ug.u_id ='$ownerID' and pr.perms = 15))";
        } else {
            $where_pg = "p.deleted=0 AND pg.perms = 63";
            $where_pr = "pr.deleted=0 AND pr.perms = 63";
        }
        $sql="SELECT DISTINCT p.id, p.name, p.perms, p.group_id, p.owner_id, p.publish
              FROM process p
              LEFT JOIN user_group ug ON  p.group_id=ug.g_id
              INNER JOIN process_group pg
              ON p.process_group_id = pg.id and pg.group_name='$parent' and $where_pg
              INNER JOIN (
                SELECT pr.process_gid, MAX(pr.rev_id) rev_id
                FROM process pr
                LEFT JOIN user_group ug ON pr.group_id=ug.g_id where $where_pr
                GROUP BY pr.process_gid
              ) b ON p.rev_id = b.rev_id AND p.process_gid=b.process_gid";

        return self::queryTable($sql);
    }
    //new
    public function getSubMenuFromSideBarPipe($parent, $ownerID){
        if ($ownerID != ''){
            $userRoleArr = json_decode($this->getUserRole($ownerID));
            $userRole = $userRoleArr[0]->{'role'};
            if ($userRole == "admin"){
                $sql="SELECT DISTINCT p.id, p.name, p.perms, p.group_id, p.owner_id, p.publish, p.pin
                  FROM biocorepipe_save p
                  INNER JOIN pipeline_group pg
                  ON p.pipeline_group_id = pg.id and pg.group_name='$parent'
                  INNER JOIN (
                    SELECT pr.pipeline_gid, MAX(pr.rev_id) rev_id
                    FROM biocorepipe_save pr
                    WHERE pr.deleted=0
                    GROUP BY pr.pipeline_gid
                  ) b ON p.rev_id = b.rev_id AND p.pipeline_gid=b.pipeline_gid AND p.deleted = 0 ";
                return self::queryTable($sql);
            }
            $where_pg = "p.deleted=0 AND (pg.owner_id='$ownerID' OR pg.perms = 63 OR (ug.u_id ='$ownerID' and pg.perms = 15))";
            $where_pr = "pr.deleted=0 AND (pr.owner_id='$ownerID' OR pr.perms = 63 OR (ug.u_id ='$ownerID' and pr.perms = 15))";
        } else {
            $where_pg = "p.deleted=0 AND pg.perms = 63";
            $where_pr = "pr.deleted=0 AND pr.perms = 63";
        }
        $sql="SELECT DISTINCT p.id, p.name, p.perms, p.group_id, p.pin
              FROM biocorepipe_save p
              LEFT JOIN user_group ug ON  p.group_id=ug.g_id
              INNER JOIN pipeline_group pg
              ON p.pipeline_group_id = pg.id and pg.group_name='$parent' and $where_pg
              INNER JOIN (
                SELECT pr.pipeline_gid, MAX(pr.rev_id) rev_id
                FROM biocorepipe_save pr
                LEFT JOIN user_group ug ON pr.group_id=ug.g_id where $where_pr
                GROUP BY pr.pipeline_gid
              ) b ON p.rev_id = b.rev_id AND p.pipeline_gid=b.pipeline_gid AND p.deleted = 0";

        return self::queryTable($sql);
    }

    public function getParentSideBarProject($ownerID){
        $sql= "SELECT DISTINCT pp.name, pp.id
              FROM project pp
              LEFT JOIN user_group ug ON pp.group_id=ug.g_id
              where pp.deleted =0 AND pp.owner_id = '$ownerID' OR pp.perms = 63 OR (ug.u_id ='$ownerID' and pp.perms = 15)";
        return self::queryTable($sql);
    }
    public function getSubMenuFromSideBarProject($parent, $ownerID){
        $where = "pp.deleted = 0 AND (pp.project_id='$parent' AND (pp.owner_id = '$ownerID' OR pp.perms = 63 OR (ug.u_id ='$ownerID' and pp.perms = 15)))";
        $sql="SELECT DISTINCT pp.id, pp.name, pj.owner_id, pp.project_id
              FROM project_pipeline pp
              LEFT JOIN user_group ug ON pp.group_id=ug.g_id
              INNER JOIN project pj ON pp.project_id = pj.id and $where ";
        return self::queryTable($sql);
    }


    //    ---------------  Users ---------------
    public function getUserByGoogleId($google_id) {
        $sql = "SELECT * FROM users WHERE google_id = '$google_id'";
        return self::queryTable($sql);
    }
    public function getUserById($id) {
        $sql = "SELECT * FROM users WHERE id = '$id'";
        return self::queryTable($sql);
    }
    public function getUserByEmail($email) {
        $email = str_replace("'", "''", $email);
        $sql = "SELECT * FROM users WHERE email = '$email'";
        return self::queryTable($sql);
    }
    public function insertGoogleUser($google_id, $email, $google_image) {
        $email = str_replace("'", "''", $email);
        $sql = "INSERT INTO users(google_id, email, google_image, username, institute, lab, memberdate, date_created, date_modified, perms) VALUES
              ('$google_id', '$email', '$google_image', '', '', '', now() , now(), now(), '3')";
        return self::insTable($sql);
    }
    public function updateGoogleUser($id, $google_id, $email, $google_image) {
        $email = str_replace("'", "''", $email);
        $sql = "UPDATE users SET google_id='$google_id', email='$email', google_image='$google_image', last_modified_user='$id' WHERE id = '$id'";
        return self::runSQL($sql);
    }

    public function updateUser($id, $name, $username, $institute, $lab, $verification) {
        $sql = "UPDATE users SET name='$name', institute='$institute', username='$username', lab='$lab', verification='$verification', last_modified_user='$id' WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function updateUserManual($id, $name, $email, $username, $institute, $lab, $logintype, $ownerID) {
        $email = str_replace("'", "''", $email);
        $sql = "UPDATE users SET name='$name', institute='$institute', username='$username', lab='$lab', logintype='$logintype', email='$email', last_modified_user='$ownerID' WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function updateUserPassword($id, $pass_hash, $ownerID) {
        $sql = "UPDATE users SET pass_hash='$pass_hash', last_modified_user='$ownerID' WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function insertUserManual($name, $email, $username, $institute, $lab, $logintype) {
        $email = str_replace("'", "''", $email);
        $sql = "INSERT INTO users(name, email, username, institute, lab, logintype, role, active, memberdate, date_created, date_modified, perms) VALUES
              ('$name', '$email', '$username', '$institute', '$lab', '$logintype','user', 1, now() , now(), now(), '3')";
        return self::insTable($sql);
    }
    public function checkExistUser($id,$username,$email) {
        $email = str_replace("'", "''", $email);
        $error = array();
        if (!empty($id)){//update
            //check if username or e-mail is altered
            $userData = json_decode($this->getUserById($id))[0];
            $usernameDB = $userData->{'username'};
            $emailDB = $userData->{'email'};
            if ($usernameDB != $username){
                $checkUsername = $this->queryAVal("SELECT id FROM users WHERE username = LCASE('" .$username. "')");
            }
            if ($emailDB != $email){
                $checkEmail = $this->queryAVal("SELECT id FROM users WHERE email = LCASE('" .$email. "')");
            }
        } else { //insert
            $checkUsername = $this->queryAVal("SELECT id FROM users WHERE username = LCASE('" .$username. "')");
            $checkEmail = $this->queryAVal("SELECT id FROM users WHERE email = LCASE('" .$email. "')");
        }
        if (!empty($checkUsername)){
            $error['username'] ="This username already exists.";
        }
        if (!empty($checkEmail)){
            $error['email'] ="This e-mail already exists.";
        }
        return $error;
    }

    public function changeActiveUser($user_id, $type) {
        if ($type == "activate" || $type == "activateSendUser"){
            $active = 1;
            $verify = "verification=NULL,";
        } else {
            $active = "NULL";
            $verify = "";
        }
        $sql = "UPDATE users SET $verify active=$active, last_modified_user='$user_id' WHERE id = '$user_id'";
        return self::runSQL($sql);
    }
    public function changeRoleUser($user_id, $type) {
        $sql = "UPDATE users SET role='$type', last_modified_user='$user_id' WHERE id = '$user_id'";
        return self::runSQL($sql);
    }

    //    ------------- Profiles   ------------
    public function insertSSH($name, $check_userkey, $check_ourkey, $ownerID) {
        $sql = "INSERT INTO ssh(name, check_userkey, check_ourkey, date_created, date_modified, last_modified_user, perms, owner_id) VALUES
              ('$name', '$check_userkey', '$check_ourkey', now() , now(), '$ownerID', '3', '$ownerID')";
        return self::insTable($sql);
    }
    public function updateSSH($id, $name, $check_userkey, $check_ourkey, $ownerID) {
        $sql = "UPDATE ssh SET name='$name', check_userkey='$check_userkey', check_ourkey='$check_ourkey', date_modified = now(), last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function insertAmz($name, $amz_def_reg, $amz_acc_key, $amz_suc_key, $ownerID) {
        $sql = "INSERT INTO amazon_credentials (name, amz_def_reg, amz_acc_key, amz_suc_key, date_created, date_modified, last_modified_user, perms, owner_id) VALUES
              ('$name', '$amz_def_reg', '$amz_acc_key', '$amz_suc_key', now() , now(), '$ownerID', '3', '$ownerID')";
        return self::insTable($sql);
    }
    public function updateAmz($id, $name, $amz_def_reg,$amz_acc_key,$amz_suc_key, $ownerID) {
        $sql = "UPDATE amazon_credentials SET name='$name', amz_def_reg='$amz_def_reg', amz_acc_key='$amz_acc_key', amz_suc_key='$amz_suc_key', date_modified = now(), last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function getAmz($ownerID) {
        $sql = "SELECT id, name, owner_id, group_id, perms, date_created, date_modified, last_modified_user FROM amazon_credentials WHERE owner_id = '$ownerID'";
        return self::queryTable($sql);
    }
    public function getAmzbyID($id,$ownerID) {
        $sql = "SELECT * FROM amazon_credentials WHERE owner_id = '$ownerID' and id = '$id'";
        return self::queryTable($sql);
    }
    public function getSSH($ownerID) {
        $sql = "SELECT * FROM ssh WHERE owner_id = '$ownerID'";
        return self::queryTable($sql);
    }
    public function getSSHbyID($id,$ownerID) {
        $sql = "SELECT * FROM ssh WHERE owner_id = '$ownerID' and id = '$id'";
        return self::queryTable($sql);
    }
    public function getProfileClusterbyID($id, $ownerID) {
        $sql = "SELECT * FROM profile_cluster WHERE owner_id = '$ownerID' and id = '$id'";
        return self::queryTable($sql);
    }
    public function getProfileCluster($ownerID) {
        $sql = "SELECT * FROM profile_cluster WHERE (public != '1' OR public IS NULL) AND owner_id = '$ownerID'";
        return self::queryTable($sql);
    }
    public function getCollections($ownerID) {
        $sql = "SELECT id, name FROM collection WHERE owner_id = '$ownerID'";
        return self::queryTable($sql);
    }
    public function getCollectionById($id,$ownerID) {
        $sql = "SELECT id, name FROM collection WHERE id = '$id' AND owner_id = '$ownerID'";
        return self::queryTable($sql);
    }
    public function getFiles($ownerID) {
        $sql = "SELECT DISTINCT f.id, f.name, f.files_used, f.file_dir, f.collection_type, f.archive_dir, f.s3_archive_dir, f.date_created, f.date_modified, f.last_modified_user, f.file_type, f.run_env, 
              GROUP_CONCAT( DISTINCT fp.p_id order by fp.p_id) as p_id,
              GROUP_CONCAT( DISTINCT p.name order by p.name) as project_name,
              GROUP_CONCAT( DISTINCT c.name order by c.name) as collection_name,
              GROUP_CONCAT( DISTINCT c.id order by c.id) as collection_id
              FROM file f
              LEFT JOIN file_collection fc  ON f.id = fc.f_id
              LEFT JOIN file_project fp ON f.id = fp.f_id
              LEFT JOIN collection c on fc.c_id = c.id
              LEFT JOIN project p on fp.p_id = p.id
              WHERE f.owner_id = '$ownerID' AND f.deleted = 0 AND (fc.deleted = 0 OR fc.deleted IS NULL) AND (fp.deleted = 0 OR fp.deleted IS NULL) AND (p.deleted = 0 OR p.deleted IS NULL)
              GROUP BY f.id, f.name, f.files_used, f.file_dir, f.collection_type, f.archive_dir, f.s3_archive_dir, f.date_created, f.date_modified, f.last_modified_user, f.file_type, f.run_env";
        return self::queryTable($sql);
    }
    public function getPublicProfileCluster($ownerID) {
        $sql = "SELECT * FROM profile_cluster WHERE public = '1'";
        return self::queryTable($sql);
    }
    public function getProfileAmazon($ownerID) {
        $sql = "SELECT * FROM profile_amazon WHERE (public != '1' OR public IS NULL) AND owner_id = '$ownerID'";
        return self::queryTable($sql);
    }
    public function getPublicProfileAmazon($ownerID) {
        $sql = "SELECT * FROM profile_amazon WHERE public = '1'";
        return self::queryTable($sql);
    }
    public function getProfileAmazonbyID($id, $ownerID) {
        $sql = "SELECT p.*, u.username
              FROM profile_amazon p
              INNER JOIN users u ON p.owner_id = u.id
              WHERE p.owner_id = '$ownerID' and p.id = '$id'";
        return self::queryTable($sql);
    }
    public function getActiveRunbyProID($id, $ownerID) {
        $sql = "SELECT DISTINCT pp.id, pp.output_dir, pp.profile, pp.last_run_uuid, pp.date_modified, pp.owner_id, r.run_status
            FROM project_pipeline pp
            INNER JOIN run_log r
            WHERE pp.last_run_uuid = r.run_log_uuid AND pp.deleted=0 AND pp.owner_id = '$ownerID' AND pp.profile = 'amazon-$id' AND (r.run_status = 'init' OR r.run_status = 'Waiting' OR r.run_status = 'NextRun')";
        return self::queryTable($sql);
    }
    public function insertProfileLocal($name, $executor,$next_path, $cmd, $next_memory, $next_queue, $next_time, $next_cpu, $executor_job, $job_memory, $job_queue, $job_time, $job_cpu, $ownerID) {
        $sql = "INSERT INTO profile_local (name, executor, next_path, cmd, next_memory, next_queue, next_time, next_cpu, executor_job, job_memory, job_queue, job_time, job_cpu, owner_id, perms, date_created, date_modified, last_modified_user) VALUES ('$name', '$executor','$next_path', '$cmd', '$next_memory', '$next_queue', '$next_time', '$next_cpu', '$executor_job', '$job_memory', '$job_queue', '$job_time', '$job_cpu', '$ownerID', 3, now(), now(), '$ownerID')";
        return self::insTable($sql);
    }
    public function updateProfileLocal($id, $name, $executor,$next_path, $cmd, $next_memory, $next_queue, $next_time, $next_cpu, $executor_job, $job_memory, $job_queue, $job_time, $job_cpu, $ownerID) {
        $sql = "UPDATE profile_local SET name='$name', executor='$executor', next_path='$next_path', cmd='$cmd', next_memory='$next_memory', next_queue='$next_queue', next_time='$next_time', next_cpu='$next_cpu', executor_job='$executor_job', job_memory='$job_memory', job_queue='$job_queue', job_time='$job_time', job_cpu='$job_cpu',  last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }

    public function insertProfileCluster($name, $executor,$next_path, $port, $singu_cache, $username, $hostname, $cmd, $next_memory, $next_queue, $next_time, $next_cpu, $executor_job, $job_memory, $job_queue, $job_time, $job_cpu, $next_clu_opt, $job_clu_opt, $ssh_id, $public, $ownerID) {
        $sql = "INSERT INTO profile_cluster(name, executor, next_path, port, singu_cache, username, hostname, cmd, next_memory, next_queue, next_time, next_cpu, executor_job, job_memory, job_queue, job_time, job_cpu, ssh_id, next_clu_opt, job_clu_opt, public, owner_id, perms, date_created, date_modified, last_modified_user) VALUES('$name', '$executor', '$next_path', '$port', '$singu_cache', '$username', '$hostname', '$cmd', '$next_memory', '$next_queue', '$next_time', '$next_cpu', '$executor_job', '$job_memory', '$job_queue', '$job_time', '$job_cpu', '$ssh_id', '$next_clu_opt','$job_clu_opt', '$public','$ownerID', 3, now(), now(), '$ownerID')";
        return self::insTable($sql);
    }
    public function updateProfileCluster($id, $name, $executor,$next_path, $port, $singu_cache, $username, $hostname, $cmd, $next_memory, $next_queue, $next_time, $next_cpu, $executor_job, $job_memory, $job_queue, $job_time, $job_cpu, $next_clu_opt, $job_clu_opt, $ssh_id, $public, $ownerID) {
        $sql = "UPDATE profile_cluster SET name='$name', executor='$executor', next_path='$next_path', port='$port', singu_cache='$singu_cache', username='$username', hostname='$hostname', cmd='$cmd', next_memory='$next_memory', next_queue='$next_queue', next_time='$next_time', next_cpu='$next_cpu', executor_job='$executor_job', job_memory='$job_memory', job_queue='$job_queue', job_time='$job_time', job_cpu='$job_cpu', job_clu_opt='$job_clu_opt', next_clu_opt='$next_clu_opt', ssh_id='$ssh_id', public='$public', last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function insertProfileAmazon($name, $executor, $next_path, $port, $singu_cache, $ins_type, $image_id, $cmd, $next_memory, $next_queue, $next_time, $next_cpu, $executor_job, $job_memory, $job_queue, $job_time, $job_cpu, $subnet_id, $shared_storage_id,$shared_storage_mnt, $ssh_id, $amazon_cre_id, $next_clu_opt, $job_clu_opt, $public, $security_group, $ownerID) {
        $sql = "INSERT INTO profile_amazon(name, executor, next_path, port, singu_cache, instance_type, image_id, cmd, next_memory, next_queue, next_time, next_cpu, executor_job, job_memory, job_queue, job_time, job_cpu, subnet_id, shared_storage_id, shared_storage_mnt, ssh_id, amazon_cre_id, next_clu_opt, job_clu_opt, public, security_group, owner_id, perms, date_created, date_modified, last_modified_user) VALUES('$name', '$executor', '$next_path', '$port', '$singu_cache', '$ins_type', '$image_id', '$cmd', '$next_memory', '$next_queue', '$next_time', '$next_cpu', '$executor_job', '$job_memory', '$job_queue', '$job_time', '$job_cpu', '$subnet_id','$shared_storage_id','$shared_storage_mnt','$ssh_id','$amazon_cre_id', '$next_clu_opt', '$job_clu_opt', '$public', '$security_group', '$ownerID', 3, now(), now(), '$ownerID')";
        return self::insTable($sql);
    }
    public function updateProfileAmazon($id, $name, $executor, $next_path, $port, $singu_cache, $ins_type, $image_id, $cmd, $next_memory, $next_queue, $next_time, $next_cpu, $executor_job, $job_memory, $job_queue, $job_time, $job_cpu, $subnet_id, $shared_storage_id, $shared_storage_mnt, $ssh_id, $amazon_cre_id, $next_clu_opt, $job_clu_opt, $public, $security_group, $ownerID) {
        $sql = "UPDATE profile_amazon SET name='$name', executor='$executor', next_path='$next_path', port='$port', singu_cache='$singu_cache', instance_type='$ins_type', image_id='$image_id', cmd='$cmd', next_memory='$next_memory', next_queue='$next_queue', next_time='$next_time', next_cpu='$next_cpu', executor_job='$executor_job', job_memory='$job_memory', job_queue='$job_queue', job_time='$job_time', job_cpu='$job_cpu', subnet_id='$subnet_id', shared_storage_id='$shared_storage_id', shared_storage_mnt='$shared_storage_mnt', ssh_id='$ssh_id', next_clu_opt='$next_clu_opt', job_clu_opt='$job_clu_opt', amazon_cre_id='$amazon_cre_id', public='$public', security_group='$security_group', last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function updateProfileAmazonOnStart($id, $nodes, $autoscale_check, $autoscale_maxIns, $autoscale_minIns, $autoshutdown_date, $autoshutdown_active, $autoshutdown_check, $ownerID) {
        $sql = "UPDATE profile_amazon SET nodes='$nodes', autoscale_check='$autoscale_check', autoscale_maxIns='$autoscale_maxIns', autoscale_minIns='$autoscale_minIns',  autoshutdown_date=".($autoshutdown_date==NULL ? "NULL" : "'$autoshutdown_date'").", autoshutdown_active='$autoshutdown_active', autoshutdown_check='$autoshutdown_check', last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function updateAmzShutdownDate($id, $autoshutdown_date, $ownerID) {
        $sql = "UPDATE profile_amazon SET autoshutdown_date=".($autoshutdown_date==NULL ? "NULL" : "'$autoshutdown_date'").", last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function updateAmzShutdownActive($id, $autoshutdown_active, $ownerID) {
        $sql = "UPDATE profile_amazon SET autoshutdown_active='$autoshutdown_active', last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function updateAmzShutdownCheck($id, $autoshutdown_check, $ownerID) {
        $sql = "UPDATE profile_amazon SET autoshutdown_check='$autoshutdown_check', date_modified= now(), last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function updateAmazonProStatus($id, $status, $ownerID) {
        $sql = "UPDATE profile_amazon SET status='$status', date_modified= now(), last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function updateAmazonProNodeStatus($id, $node_status, $ownerID) {
        $sql = "UPDATE profile_amazon SET node_status='$node_status', date_modified= now(), last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function updateAmazonProPid($id, $pid, $ownerID) {
        $sql = "UPDATE profile_amazon SET pid='$pid', date_modified= now(), last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function updateAmazonProSSH($id, $sshText, $ownerID) {
        $sql = "UPDATE profile_amazon SET ssh='$sshText', date_modified= now(), last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function getAmazonProSSH($id, $ownerID) {
        $sql = "SELECT ssh FROM profile_amazon WHERE id = '$id' AND owner_id = '$ownerID'";
        return self::queryTable($sql);
    }
    public function removeAmz($id) {
        $sql = "DELETE FROM amazon_credentials WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeSSH($id) {
        $sql = "DELETE FROM ssh WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProLocal($id) {
        $sql = "DELETE FROM profile_local WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProCluster($id) {
        $sql = "DELETE FROM profile_cluster WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProAmazon($id) {
        $sql = "DELETE FROM profile_amazon WHERE id = '$id'";
        return self::runSQL($sql);
    }
    //    ------------- Parameters ------------
    public function getAllParameters($ownerID) {
        if ($ownerID == ""){
            $ownerID ="''";
        } else {
            $userRoleCheck = $this->getUserRole($ownerID);
            if (isset(json_decode($userRoleCheck)[0])){
                $userRole = json_decode($userRoleCheck)[0]->{'role'};
                if ($userRole == "admin"){
                    $sql = "SELECT DISTINCT p.id, p.file_type, p.qualifier, p.name, p.group_id, p.perms FROM parameter p";
                    return self::queryTable($sql);
                }
            }
        }

        $sql = "SELECT DISTINCT p.id, p.file_type, p.qualifier, p.name, p.group_id, p.perms
              FROM parameter p
              LEFT JOIN user_group ug ON p.group_id=ug.g_id
              WHERE p.owner_id = '$ownerID' OR p.perms = 63 OR (ug.u_id ='$ownerID' and p.perms = 15)";
        return self::queryTable($sql);
    }
    public function getEditDelParameters($ownerID) {
        $sql = "SELECT DISTINCT * FROM parameter p
              WHERE p.owner_id = '$ownerID' AND id not in (select parameter_id from process_parameter WHERE owner_id != '$ownerID')";
        return self::queryTable($sql);
    }

    public function insertParameter($name, $qualifier, $file_type, $ownerID) {
        $sql = "INSERT INTO parameter(name, qualifier, file_type, owner_id, perms, date_created, date_modified, last_modified_user) VALUES
              ('$name', '$qualifier', '$file_type', '$ownerID', 63, now(), now(), '$ownerID')";
        return self::insTable($sql);
    }

    public function updateParameter($id, $name, $qualifier, $file_type, $ownerID) {
        $sql = "UPDATE parameter SET name='$name', qualifier='$qualifier', last_modified_user ='$ownerID', file_type='$file_type'  WHERE id = '$id'";
        return self::runSQL($sql);
    }

    public function insertProcessGroup($group_name, $ownerID) {
        $sql = "INSERT INTO process_group (owner_id, group_name, date_created, date_modified, last_modified_user, perms) VALUES ('$ownerID', '$group_name', now(), now(), '$ownerID', 63)";
        return self::insTable($sql);
    }

    public function updateProcessGroup($id, $group_name, $ownerID) {
        $sql = "UPDATE process_group SET group_name='$group_name', last_modified_user ='$ownerID', date_modified=now()  WHERE id = '$id'";
        return self::runSQL($sql);
    }

    public function updateAllProcessGroupByGid($process_gid, $process_group_id,$ownerID) {
        $sql = "UPDATE process SET process_group_id='$process_group_id', last_modified_user ='$ownerID', date_modified=now()  WHERE process_gid = '$process_gid' AND owner_id = '$ownerID'";
        return self::runSQL($sql);
    }

    public function updateAllProcessNameByGid($process_gid, $name, $ownerID) {
        $sql = "UPDATE process SET name='$name', last_modified_user ='$ownerID', date_modified=now()  WHERE process_gid = '$process_gid' AND owner_id = '$ownerID'";
        return self::runSQL($sql);
    }

    public function updateAllPipelineGroupByGid($pipeline_gid, $pipeline_group_id,$ownerID) {
        $sql = "UPDATE biocorepipe_save SET pipeline_group_id='$pipeline_group_id', last_modified_user ='$ownerID', date_modified=now() WHERE deleted = 0 AND pipeline_gid = '$pipeline_gid' AND owner_id = '$ownerID'";
        return self::runSQL($sql);
    }

    public function removeParameter($id) {
        $sql = "DELETE FROM parameter WHERE id = '$id'";
        return self::runSQL($sql);
    }

    public function removeProcessGroup($id) {
        $sql = "DELETE FROM process_group WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removePipelineGroup($id) {
        $sql = "DELETE FROM pipeline_group WHERE id = '$id'";
        return self::runSQL($sql);
    }
    // --------- Process -----------
    public function getAllProcessGroups($ownerID) {
        $sql = "SELECT DISTINCT pg.id, pg.group_name
              FROM process_group pg";
        return self::queryTable($sql);
    }
    public function getProcessGroupById($id) {
        $sql = "SELECT DISTINCT pg.group_name
              FROM process_group pg
              WHERE pg.id = '$id'";
        return self::queryTable($sql);
    }
    public function getProcessGroupByName($group_name) {
        $sql = "SELECT DISTINCT pg.id
              FROM process_group pg
              WHERE pg.group_name = '$group_name'";
        return self::queryTable($sql);
    }
    public function getCollectionByName($col_name, $owner_id) {
        $sql = "SELECT DISTINCT c.id
              FROM collection c
              WHERE c.name = '$col_name' AND owner_id='$owner_id'";
        return self::queryTable($sql);
    }
    public function getPipelineGroupByName($group_name) {
        $sql = "SELECT DISTINCT pg.id
              FROM pipeline_group pg
              WHERE pg.group_name = '$group_name'";
        return self::queryTable($sql);
    }
    public function getParameterByName($name, $qualifier, $file_type) {
        $sql = "SELECT DISTINCT id FROM parameter
              WHERE name = '$name' AND qualifier = '$qualifier' AND file_type = '$file_type'";
        return self::queryTable($sql);
    }
    public function getEditDelProcessGroups($ownerID) {
        $sql = "SELECT DISTINCT id, group_name
              FROM process_group pg
              Where pg.owner_id = '$ownerID' AND id not in (select process_group_id from process Where owner_id != '$ownerID')";
        return self::queryTable($sql);
    }

    public function insertProcess($name, $process_gid, $summary, $process_group_id, $script, $script_header, $script_footer, $rev_id, $rev_comment, $group, $perms, $publish, $script_mode, $script_mode_header, $process_uuid, $process_rev_uuid, $ownerID) {
        $sql = "INSERT INTO process(name, process_gid, summary, process_group_id, script, script_header, script_footer, rev_id, rev_comment, owner_id, date_created, date_modified, last_modified_user, perms, group_id, publish, script_mode, script_mode_header, process_uuid, process_rev_uuid) VALUES ('$name', '$process_gid', '$summary', '$process_group_id', '$script', '$script_header', '$script_footer', '$rev_id','$rev_comment', '$ownerID', now(), now(), '$ownerID', '$perms', '$group', '$publish','$script_mode', '$script_mode_header', '$process_uuid', '$process_rev_uuid')";
        return self::insTable($sql);
    }

    public function updateProcess($id, $name, $process_gid, $summary, $process_group_id, $script, $script_header, $script_footer, $group, $perms, $publish, $script_mode, $script_mode_header, $ownerID) {
        $sql = "UPDATE process SET name= '$name', process_gid='$process_gid', summary='$summary', process_group_id='$process_group_id', script='$script', script_header='$script_header',  script_footer='$script_footer', last_modified_user='$ownerID', group_id='$group', perms='$perms', publish='$publish', script_mode='$script_mode', date_modified = now(), script_mode_header='$script_mode_header' WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProcess($id) {
        $sql = "DELETE FROM process WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProject($id) {
        $sql = "UPDATE project SET deleted = 1, date_modified = now() WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeGroup($id) {
        $sql = "DELETE FROM groups WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeUserGroup($id) {
        $sql = "DELETE FROM user_group WHERE g_id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProjectPipeline($id) {
        $sql = "UPDATE project_pipeline SET deleted = 1, date_modified = now() WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeRun($id) {
        $sql = "UPDATE run SET deleted = 1, date_modified = now() WHERE project_pipeline_id = '$id'";
        return self::runSQL($sql);
    }
    public function removeInput($id) {
        $sql = "DELETE FROM input WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeFile($id, $ownerID) {
        $sql = "UPDATE file SET deleted = 1, date_modified = now() WHERE id = '$id' AND owner_id='$ownerID'";
        return self::runSQL($sql);
    }
    public function removeFileProject($id, $ownerID) {
        $sql = "UPDATE file_project SET deleted = 1, date_modified = now() WHERE f_id = '$id' AND owner_id='$ownerID'";
        return self::runSQL($sql);
    }
    public function removeFileCollection($id, $ownerID) {
        $sql = "UPDATE file_collection SET deleted = 1, date_modified = now() WHERE f_id = '$id' AND owner_id='$ownerID'";
        return self::runSQL($sql);
    }
    public function removeProjectPipelineInput($id) {
        $sql = "UPDATE project_pipeline_input SET deleted = 1, date_modified = now() WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProjectPipelineInputByPipe($id) {
        $sql = "UPDATE project_pipeline_input SET deleted = 1, date_modified = now() WHERE project_pipeline_id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProjectInput($id) {
        $sql = "DELETE FROM project_input WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProjectPipelinebyProjectID($id) {
        $sql = "UPDATE project_pipeline SET deleted = 1, date_modified = now() WHERE project_id = '$id'";
        return self::runSQL($sql);
    }
    public function removeRunByProjectID($id) {
        $sql = "UPDATE run
              JOIN project_pipeline ON project_pipeline.id = run.project_pipeline_id
              SET run.deleted = 1 WHERE project_pipeline.project_id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProjectPipelineInputbyProjectID($id) {
        $sql = "UPDATE project_pipeline_input SET deleted = 1, date_modified = now() WHERE project_id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProjectInputbyProjectID($id) {
        $sql = "DELETE FROM project_input WHERE project_id = '$id'";
        return self::runSQL($sql);
    }
    public function removeProcessByProcessGroupID($process_group_id) {
        $sql = "DELETE FROM process WHERE process_group_id = '$process_group_id'";
        return self::runSQL($sql);
    }
    //    ------ Groups -------
    public function getAllGroups() {
        $sql = "SELECT id, name FROM groups";
        return self::queryTable($sql);
    }
    public function getGroups($id,$ownerID) {
        $where = "";
        if ($id != ""){
            $where = " where g.id = '$id'";
        }
        $sql = "SELECT g.id, g.name, g.date_created, u.username, g.date_modified
              FROM groups g
              INNER JOIN users u ON g.owner_id = u.id $where";
        return self::queryTable($sql);
    }
    public function viewGroupMembers($g_id) {
        $sql = "SELECT id, username, email
              FROM users
              WHERE id in (
                SELECT u_id
                FROM user_group
                WHERE g_id = '$g_id')";
        return self::queryTable($sql);
    }
    public function getMemberAdd($g_id) {
        $sql = "SELECT id, username, email
                FROM users
                WHERE id NOT IN (
                  SELECT u_id
                  FROM user_group
                  WHERE g_id = '$g_id')";
        return self::queryTable($sql);
    }
    public function getAllUsers($ownerID) {
        $userRoleCheck = $this->getUserRole($ownerID);
        if (isset(json_decode($userRoleCheck)[0])){
            $userRole = json_decode($userRoleCheck)[0]->{'role'};
            if ($userRole == "admin"){
                $sql = "SELECT *
                      FROM users
                      WHERE id <> '$ownerID'";
                return self::queryTable($sql);
            }
        }
    }

    public function getUserGroups($ownerID) {
        $sql = "SELECT g.id, g.name, g.date_created, u.username, g.owner_id, ug.u_id
                  FROM groups g
                  INNER JOIN user_group ug ON  ug.g_id =g.id
                  INNER JOIN users u ON u.id = g.owner_id
                  where ug.u_id = '$ownerID'";
        return self::queryTable($sql);
    }
    public function getUserRole($ownerID) {
        $sql = "SELECT role
                  FROM users
                  where id = '$ownerID'";
        return self::queryTable($sql);
    }
    public function insertGroup($name, $ownerID) {
        $sql = "INSERT INTO groups(name, owner_id, date_created, date_modified, last_modified_user, perms) VALUES ('$name', '$ownerID', now(), now(), '$ownerID', 3)";
        return self::insTable($sql);
    }
    public function insertUserGroup($g_id, $u_id, $ownerID) {
        $sql = "INSERT INTO user_group (g_id, u_id, owner_id, date_created, date_modified, last_modified_user, perms) VALUES ('$g_id', '$u_id', '$ownerID', now(), now(), '$ownerID', 3)";
        return self::insTable($sql);
    }
    public function updateGroup($id, $name, $ownerID) {
        $sql = "UPDATE groups SET name= '$name', last_modified_user = '$ownerID', date_modified = now() WHERE id = '$id'";
        return self::runSQL($sql);
    }
    //    ----------- Projects   ---------
    public function getProjects($id,$ownerID) {
        $where = " where p.deleted=0 AND p.owner_id = '$ownerID' OR p.perms = 63 OR (ug.u_id ='$ownerID' and p.perms = 15)";
        if ($id != ""){
            $where = " where p.deleted=0 AND p.id = '$id' AND (p.owner_id = '$ownerID' OR p.perms = 63 OR (ug.u_id ='$ownerID' and p.perms = 15))";
        }
        $sql = "SELECT DISTINCT p.id, p.name, p.summary, p.date_created, u.username, p.date_modified, IF(p.owner_id='$ownerID',1,0) as own
                  FROM project p
                  INNER JOIN users u ON p.owner_id = u.id
                  LEFT JOIN user_group ug ON p.group_id=ug.g_id
                  $where";
        return self::queryTable($sql);
    }
    public function insertProject($name, $summary, $ownerID) {
        $sql = "INSERT INTO project(name, summary, owner_id, date_created, date_modified, last_modified_user, perms) VALUES ('$name', '$summary', '$ownerID', now(), now(), '$ownerID', 3)";
        return self::insTable($sql);
    }
    public function updateProject($id, $name, $summary, $ownerID) {
        $sql = "UPDATE project SET name= '$name', summary= '$summary', last_modified_user = '$ownerID', date_modified = now() WHERE id = '$id'";
        return self::runSQL($sql);
    }
    //    ----------- Runs     ---------
    public function insertRun($project_pipeline_id, $status, $attempt, $ownerID) {
        $sql = "INSERT INTO run (project_pipeline_id, run_status, attempt, owner_id, perms, date_created, date_modified, last_modified_user) VALUES
                  ('$project_pipeline_id', '$status', '$attempt', '$ownerID', 3, now(), now(), '$ownerID')";
        return self::insTable($sql);
    }
    public function insertRunLog($project_pipeline_id, $uuid, $status, $ownerID) {
        $sql = "INSERT INTO run_log (project_pipeline_id, run_log_uuid, run_status, owner_id, perms, date_created, date_modified, last_modified_user) VALUES
                  ('$project_pipeline_id', '$uuid', '$status', '$ownerID', 3, now(), now(), '$ownerID')";
        return self::insTable($sql);
    }
    //get maximum of $project_pipeline_id
    public function updateRunLog($project_pipeline_id, $status, $duration, $ownerID) {
        $sql = "UPDATE run_log SET run_status='$status', duration='$duration', date_ended= now(), date_modified= now(), last_modified_user ='$ownerID'  WHERE project_pipeline_id = '$project_pipeline_id' ORDER BY id DESC LIMIT 1";
        return self::runSQL($sql);
    }
    public function getRunLog($project_pipeline_id) {
        $sql = "SELECT * FROM run_log WHERE project_pipeline_id = '$project_pipeline_id'";
        return self::queryTable($sql);
    }
    public function updateRunStatus($project_pipeline_id, $status, $ownerID) {
        $sql = "UPDATE run SET run_status='$status', date_modified= now(), last_modified_user ='$ownerID'  WHERE project_pipeline_id = '$project_pipeline_id'";
        return self::runSQL($sql);
    }
    public function updateRunAttempt($project_pipeline_id, $attempt, $ownerID) {
        $sql = "UPDATE run SET attempt= '$attempt', date_modified= now(), last_modified_user ='$ownerID'  WHERE project_pipeline_id = '$project_pipeline_id'";
        return self::runSQL($sql);
    }
    public function updateRunPid($project_pipeline_id, $pid, $ownerID) {
        $sql = "UPDATE run SET pid='$pid', date_modified= now(), last_modified_user ='$ownerID'  WHERE project_pipeline_id = '$project_pipeline_id'";
        return self::runSQL($sql);
    }
    public function getRunPid($project_pipeline_id) {
        $sql = "SELECT pid FROM run WHERE project_pipeline_id = '$project_pipeline_id'";
        return self::queryTable($sql);
    }
    public function getRunAttempt($project_pipeline_id) {
        $sql = "SELECT attempt FROM run WHERE project_pipeline_id = '$project_pipeline_id'";
        return self::queryTable($sql);
    }

    public function getUpload($name,$email) {
        $email = str_replace("'", "__", $email);
        $filename= "{$this->tmp_path}/uploads/$email/$name";
        // get contents of a file into a string
        $handle = fopen($filename, "r");
        $content = fread($handle, filesize($filename));
        fclose($handle);
        return json_encode($content);
    }
    public function removeUpload($name,$email) {
        $email = str_replace("'", "__", $email);
        $filename= "{$this->tmp_path}/uploads/$email/$name";
        unlink($filename);
        return json_encode("file deleted");
    }
    public function getRun($project_pipeline_id,$ownerID) {
        $sql = "SELECT * FROM run WHERE deleted = 0 AND project_pipeline_id = '$project_pipeline_id'";
        return self::queryTable($sql);
    }
    public function getRunStatus($project_pipeline_id,$ownerID) {
        $sql = "SELECT run_status FROM run WHERE deleted = 0 AND project_pipeline_id = '$project_pipeline_id'";
        return self::queryTable($sql);
    }
    public function getAmazonStatus($id,$ownerID) {
        $sql = "SELECT status, node_status FROM profile_amazon WHERE id = '$id'";
        return self::queryTable($sql);
    }
    public function getAmazonPid($id,$ownerID) {
        $sql = "SELECT pid FROM profile_amazon WHERE id = '$id'";
        return self::queryTable($sql);
    }
    public function sshExeCommand($commandType, $pid, $profileType, $profileId, $project_pipeline_id, $ownerID) {
        list($connect, $ssh_port, $scp_port, $cluDataArr) = $this->getCluAmzData($profileId, $profileType, $ownerID);
        $ssh_id = $cluDataArr[0]["ssh_id"];
        $executor = $cluDataArr[0]['executor'];
        $userpky = "{$this->ssh_path}/{$ownerID}_{$ssh_id}_ssh_pri.pky";

        //get preCmd to load prerequisites (eg: source /etc/bashrc) (to run qstat qdel)
        $proPipeAll = json_decode($this->getProjectPipelines($project_pipeline_id,"",$ownerID,""));
        $proPipeCmd = $proPipeAll[0]->{'cmd'};
        $profileCmd = $cluDataArr[0]["cmd"];
        $imageCmd = "";
        $preCmd = $this->getPreCmd($profileType, $profileCmd, $proPipeCmd, $imageCmd, "");

        if ($executor == "lsf" && $commandType == "checkRunPid"){
            $check_run = shell_exec("ssh {$this->ssh_settings} $ssh_port -i $userpky $connect \"$preCmd bjobs\" 2>&1 &");
            if (preg_match("/$pid/",$check_run)){
                return json_encode('running');
            } else {
                return json_encode('done');
            }
        } else if ($executor == "sge" && $commandType == "checkRunPid"){
            $check_run = shell_exec("ssh {$this->ssh_settings} $ssh_port -i $userpky $connect \"$preCmd qstat -j $pid\" 2>&1 &");
            if (preg_match("/job_number:/",$check_run)){
                return json_encode('running');
            } else {
                $this->updateRunPid($project_pipeline_id, "0", $ownerID);
                return json_encode('done');
            }
        } else if ($executor == "sge" && $commandType == "terminateRun"){
            $terminate_run = shell_exec("ssh {$this->ssh_settings} $ssh_port -i $userpky $connect \"$preCmd qdel $pid\" 2>&1 &");
            return json_encode('terminateCommandExecuted');
        } else if ($executor == "lsf" && $commandType == "terminateRun"){
            $terminate_run = shell_exec("ssh {$this->ssh_settings} $ssh_port -i $userpky $connect \"$preCmd bkill $pid\" 2>&1 &");
            return json_encode('terminateCommandExecuted');
        } else if ($executor == "local" && $commandType == "terminateRun"){
            $cmd = "ssh {$this->ssh_settings} $ssh_port -i $userpky $connect \"$preCmd ps -ef |grep nextflow.*/run$project_pipeline_id/ |grep -v grep | awk '{print \\\"kill \\\"\\\$2}' |bash \" 2>&1 &";
            $terminate_run = shell_exec($cmd);
            return json_encode('terminateCommandExecuted');
        }

    }
    public function terminateRun($pid, $project_pipeline_id, $ownerID) {
        $sql = "SELECT attempt FROM run WHERE project_pipeline_id = '$project_pipeline_id'";
        return self::queryTable($sql);
    }
    function file_get_contents_utf8($fn) {
        $content = file_get_contents($fn);
        return mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content, 'UTF-8, ISO-8859-1', true));
    }
    public function getFileContent($uuid, $filename, $ownerID) {
        $file = "{$this->run_path}/$uuid/$filename";
        $content = "";
        if (file_exists($file)) {
            $content = $this->file_get_contents_utf8($file);
        }
        return json_encode($content);
    }
    public function saveFileContent($text, $uuid, $filename, $ownerID) {
        $file = fopen("{$this->run_path}/$uuid/$filename", "w");
        $res = fwrite($file, $text);
        fclose($file);
        return json_encode($res);
    }

    //$last_server_dir is last directory in $uuid folder: eg. run, pubweb
    public function saveNextflowLog($files,$uuid, $last_server_dir, $profileType,$profileId,$project_pipeline_id,$ownerID) {
        list($connect, $ssh_port, $scp_port, $cluDataArr) = $this->getCluAmzData($profileId, $profileType, $ownerID);
        $ssh_id = $cluDataArr[0]["ssh_id"];
        $userpky = "{$this->ssh_path}/{$ownerID}_{$ssh_id}_ssh_pri.pky";
        if (!file_exists($userpky)) die(json_encode('Private key is not found!'));
        if (!file_exists("{$this->run_path}/$uuid/$last_server_dir")) {
            mkdir("{$this->run_path}/$uuid/$last_server_dir", 0755, true);
        }
        if (preg_match("/s3:/i", $files[0])){
            $fileList="";
            foreach ($files as $item):
            $fileList.="$item ";
            endforeach;
            $proPipeAll = json_decode($this->getProjectPipelines($project_pipeline_id,"",$ownerID,""));
            $amazon_cre_id = $proPipeAll[0]->{'amazon_cre_id'};
            if (!empty($amazon_cre_id)){
                $amz_data = json_decode($this->getAmzbyID($amazon_cre_id, $ownerID));
                foreach($amz_data as $d){
                    $access = $d->amz_acc_key;
                    $d->amz_acc_key = trim($this->amazonDecode($access));
                    $secret = $d->amz_suc_key;
                    $d->amz_suc_key = trim($this->amazonDecode($secret));
                }
                $access_key = $amz_data[0]->{'amz_acc_key'};
                $secret_key = $amz_data[0]->{'amz_suc_key'};
                $cmd="s3cmd sync --access_key $access_key  --secret_key $secret_key $fileList {$this->run_path}/$uuid/$last_server_dir/ 2>&1 &";
            }
        } else {
            $fileList="";
            foreach ($files as $item):
            $fileList.="$connect:$item ";
            endforeach;
            $cmd="rsync -avzu -e 'ssh {$this->ssh_settings} $ssh_port -i $userpky' $fileList {$this->run_path}/$uuid/$last_server_dir/ 2>&1 &"; 
        }
        $nextflow_log = shell_exec($cmd);
        // save $nextflow_log to a file
        if (!is_null($nextflow_log) && isset($nextflow_log) && $nextflow_log != "" && !empty($nextflow_log)){
            return json_encode("nextflow log saved");
        } else {
            return json_encode("logNotFound");
        }
    }

    public function getLsDir($dir, $profileType, $profileId, $amazon_cre_id, $ownerID) {
        $dir = trim($dir);
        if (preg_match("/s3:/i", $dir)){
            if (!empty($amazon_cre_id)){
                $amz_data = json_decode($this->getAmzbyID($amazon_cre_id, $ownerID));
                foreach($amz_data as $d){
                    $access = $d->amz_acc_key;
                    $d->amz_acc_key = trim($this->amazonDecode($access));
                    $secret = $d->amz_suc_key;
                    $d->amz_suc_key = trim($this->amazonDecode($secret));
                }
                $access_key = $amz_data[0]->{'amz_acc_key'};
                $secret_key = $amz_data[0]->{'amz_suc_key'};
            }
            $lastChar = substr($dir, -1);
            if ($lastChar != "/"){
                $dir = $dir."/";
            }
            $cmd="s3cmd ls --access_key $access_key  --secret_key $secret_key $dir 2>&1 &";
        } else {
            list($connect, $ssh_port, $scp_port, $cluDataArr) = $this->getCluAmzData($profileId, $profileType, $ownerID);
            $ssh_id = $cluDataArr[0]["ssh_id"];
            $userpky = "{$this->ssh_path}/{$ownerID}_{$ssh_id}_ssh_pri.pky";
            if (!file_exists($userpky)) die(json_encode('Private key is not found!'));
            $cmd="ssh {$this->ssh_settings} $ssh_port -i $userpky $connect \"ls -1 $dir\" 2>&1 &";
        }
        $log = shell_exec($cmd);
        if (!is_null($log) && isset($log) && $log != "" && !empty($log)){
            return json_encode($log);
        } else {
            return json_encode("Connection failed! Please check your connection profile or internet connection");
        }
    }

    //installed edirect(esearch,efetch) path should be added into .bashrc
    public function getSRRData($srr_id, $ownerID) {
        $obj = new stdClass();
        $command = "esearch -db sra -query $srr_id |efetch -format runinfo";
        $resText = shell_exec("$command 2>&1 & echo $! &");
        if (!empty($resText)){
            $resText = trim($resText);
            $lines = explode("\n", $resText);
            if (count($lines) == 3){
                $header = explode(",", $lines[1]);
                $vals = explode(",", $lines[2]);
                for ($i = 0; $i < count($header); $i++) {
                    $col = $header[$i];
                    if ($col == "Run"){
                        $obj->srr_id = trim($vals[$i]);
                    } else if ($col == "LibraryLayout"){
                        if (trim($vals[$i]) == "PAIRED"){
                            $obj->collection_type = "pair";
                        } else {
                            $obj->collection_type = "single";
                        }
                    }
                }
            }
        }
        return $obj;
    }

    //installed edirect(esearch,efetch) path should be added into .bashrc
    public function getGeoData($geo_id, $ownerID) {
        $data = array();
        if (preg_match("/SRR/", $geo_id) || preg_match("/GSM/", $geo_id)){
            $obj = $this->getSRRData($geo_id, $ownerID);
            $data[] = $obj;
        } else if (preg_match("/GSE/", $geo_id)){
            $command = "esearch -db gds -query $geo_id | esummary | xtract -pattern DocumentSummary -element title Accession";
            $resText = shell_exec("$command 2>&1 & echo $! &");
            if (!empty($resText)){
                $resText = trim($resText);
                $lines = explode("\n", $resText);
                for ($i = 0; $i < count($lines); $i++) {
                    $cols = explode("\t", $lines[$i]);
                    if (count($cols) == 2){
                        $obj = $this->getSRRData($cols[1], $ownerID);
                        $obj->name = trim(str_replace(" ","_",$cols[0]));
                        $data[] = $obj;
                    }
                }
            }
        }
        return json_encode($data);
    }
    public function readFileSubDir($path) {
        $scanned_directory = array_diff(scandir($path), array('..', '.'));
        foreach ($scanned_directory as $fileItem) {
            // skip '.' and '..' and .tmp hidden directories
            if ($fileItem[0] == '.')  continue;
            $fileItem = rtrim($path,'/') . '/' . $fileItem;
            // if dir found call again recursively
            if (is_dir($fileItem)) {
                foreach ($this->readFileSubDir($fileItem) as $childFileItem) {
                    yield $childFileItem;
                }
            } else {
                yield $fileItem;
            }
        }
    }

    //$last_server_dir is last directory in $uuid folder: eg. run, pubweb
    //$opt = "onlyfile", "filedir"
    public function getFileList($uuid, $last_server_dir, $opt) {
        $path= "{$this->run_path}/$uuid/$last_server_dir";
        $scanned_directory = array();
        if (file_exists($path)) {
            if ($opt == "filedir"){
                $scanned_directory = array_diff(scandir($path), array('..', '.'));
            } else if ($opt == "onlyfile"){
                //recursive read of all subdirectories
                foreach ($this->readFileSubDir($path) as $fileItem) {
                    //remove initial part of the path
                    $fileItemRet = preg_replace('/^' . preg_quote($path.'/', '/') . '/', '', $fileItem);
                    $scanned_directory[]=$fileItemRet;
                }
            }
        }
        return json_encode($scanned_directory);
    }




    //    ----------- Inputs, Project Inputs   ---------
    public function getInputs($id,$ownerID) {
        $where = "";
        if ($id != ""){
            $where = " where i.id = '$id' ";
        }
        $sql = "SELECT DISTINCT i.id, i.name, IF(i.owner_id='$ownerID',1,0) as own
                  FROM input i
                  LEFT JOIN user_group ug ON i.group_id=ug.g_id
                  $where";
        return self::queryTable($sql);
    }
    public function getProjectInputs($project_id,$ownerID) {
        $where = " where pi.project_id = '$project_id' AND (pi.owner_id = '$ownerID' OR pi.perms = 63 OR (ug.u_id ='$ownerID' and pi.perms = 15))" ;
        $sql = "SELECT DISTINCT pi.id, i.id as input_id, i.name, pi.date_modified,  IF(pi.owner_id='$ownerID',1,0) as own
                  FROM project_input pi
                  INNER JOIN input i ON i.id = pi.input_id
                  LEFT JOIN user_group ug ON pi.group_id=ug.g_id
                  $where";
        return self::queryTable($sql);
    }
    public function getProjectFiles($project_id,$ownerID) {
        $where = " where (i.type = 'file' OR i.type IS NULL) AND pi.project_id = '$project_id' AND (pi.owner_id = '$ownerID' OR pi.perms = 63 OR (ug.u_id ='$ownerID' and pi.perms = 15))" ;
        $sql = "SELECT DISTINCT pi.id, i.id as input_id, i.name, pi.date_modified,  IF(pi.owner_id='$ownerID',1,0) as own
                  FROM project_input pi
                  INNER JOIN input i ON i.id = pi.input_id
                  LEFT JOIN user_group ug ON pi.group_id=ug.g_id
                  $where";
        return self::queryTable($sql);
    }
    public function getPublicInputs($id) {
        $where = " WHERE i.perms = 63";
        if ($id != ""){
            $where = " where i.id = '$id' AND i.perms = 63";
        }
        $sql = "SELECT i.*, u.username
                  FROM input i
                  INNER JOIN users u ON i.owner_id = u.id
                  $where";
        return self::queryTable($sql);
    }
    public function getPublicFiles($host) {
        $sql = "SELECT id as input_id, name, date_modified FROM input WHERE type = 'file' AND host = '$host' AND perms = 63 ";
        return self::queryTable($sql);
    }
    public function getPublicValues($host) {
        $sql = "SELECT id as input_id, name, date_modified FROM input WHERE type = 'val' AND host = '$host' AND perms = 63 ";
        return self::queryTable($sql);
    }
    public function getProjectValues($project_id,$ownerID) {
        $where = " where (i.type = 'val' OR i.type IS NULL) AND pi.project_id = '$project_id' AND (pi.owner_id = '$ownerID' OR pi.perms = 63 OR (ug.u_id ='$ownerID' and pi.perms = 15))" ;
        $sql = "SELECT DISTINCT pi.id, i.id as input_id, i.name, pi.date_modified,  IF(pi.owner_id='$ownerID',1,0) as own
                  FROM project_input pi
                  INNER JOIN input i ON i.id = pi.input_id
                  LEFT JOIN user_group ug ON pi.group_id=ug.g_id
                  $where";
        return self::queryTable($sql);
    }
    public function getProjectInput($id,$ownerID) {
        $where = " where pi.id = '$id' AND (pi.owner_id = '$ownerID' OR pi.perms = 63)" ;
        $sql = "SELECT pi.id, i.id as input_id, i.name
                  FROM project_input pi
                  INNER JOIN input i ON i.id = pi.input_id
                  $where";
        return self::queryTable($sql);
    }
    public function insertProjectInput($project_id, $input_id, $ownerID) {
        $sql = "INSERT INTO project_input(project_id, input_id, owner_id, perms, date_created, date_modified, last_modified_user) VALUES
                  ('$project_id', '$input_id', '$ownerID', 3, now(), now(), '$ownerID')";
        return self::insTable($sql);
    }
    public function insertFile($name, $file_dir, $file_type, $files_used, $collection_type, $archive_dir, $s3_archive_dir, $run_env, $ownerID) {
        $sql = "INSERT INTO file(name, file_dir, file_type, files_used, collection_type, archive_dir, s3_archive_dir, run_env, owner_id, perms, date_created, date_modified, last_modified_user) VALUES
                  ('$name', '$file_dir', '$file_type', '$files_used', '$collection_type', '$archive_dir', '$s3_archive_dir', '$run_env', '$ownerID', 3, now(), now(), '$ownerID')";
        return self::insTable($sql);
    }
    public function insertCollection($name, $ownerID) {
        $sql = "INSERT INTO collection (name, owner_id, perms, date_created, date_modified, last_modified_user) VALUES
                  ('$name', '$ownerID', 3, now(), now(), '$ownerID')";
        return self::insTable($sql);
    }
    public function updateCollection($id, $name, $ownerID) {
        $sql = "UPDATE collection SET name='$name', date_modified= now(), last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function insertFileCollection($f_id, $c_id, $ownerID) {
        $sql = "INSERT INTO file_collection (f_id, c_id, owner_id, date_created, date_modified, last_modified_user, perms) VALUES ('$f_id', '$c_id', '$ownerID', now(), now(), '$ownerID', 3)";
        return self::insTable($sql);
    }
    public function insertFileProject($f_id, $p_id, $ownerID) {
        $sql = "INSERT INTO file_project (f_id, p_id, owner_id, date_created, date_modified, last_modified_user, perms) VALUES ('$f_id', '$p_id', '$ownerID', now(), now(), '$ownerID', 3)";
        return self::insTable($sql);
    }

    public function insertInput($name, $type, $ownerID) {
        $sql = "INSERT INTO input(name, type, owner_id, perms, date_created, date_modified, last_modified_user) VALUES
                  ('$name', '$type', '$ownerID', 3, now(), now(), '$ownerID')";
        return self::insTable($sql);
    }
    public function updateInput($id, $name, $type, $ownerID) {
        $sql = "UPDATE input SET name='$name', type='$type', date_modified= now(), last_modified_user ='$ownerID'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function insertPublicInput($name, $type, $host, $ownerID) {
        $sql = "INSERT INTO input(name, type, host, owner_id, date_created, date_modified, last_modified_user, perms) VALUES ('$name', '$type', '$host', '$ownerID', now(), now(), '$ownerID', 63)";
        return self::insTable($sql);
    }
    public function updatePublicInput($id, $name, $type, $host, $ownerID) {
        $sql = "UPDATE input SET name= '$name', type= '$type', host= '$host', last_modified_user = '$ownerID', date_modified = now() WHERE id = '$id'";
        return self::runSQL($sql);
    }

    // ------- Project Pipelines  ------
    public function insertProjectPipeline($name, $project_id, $pipeline_id, $summary, $output_dir, $profile, $interdel, $cmd, $exec_each, $exec_all, $exec_all_settings, $exec_each_settings, $docker_check, $docker_img, $singu_check, $singu_save, $singu_img, $exec_next_settings, $docker_opt, $singu_opt, $amazon_cre_id, $publish_dir, $publish_dir_check, $withReport, $withTrace, $withTimeline, $withDag, $process_opt, $ownerID) {
        $sql = "INSERT INTO project_pipeline(name, project_id, pipeline_id, summary, output_dir, profile, interdel, cmd, exec_each, exec_all, exec_all_settings, exec_each_settings, docker_check, docker_img, singu_check, singu_save, singu_img, exec_next_settings, docker_opt, singu_opt, amazon_cre_id, publish_dir, publish_dir_check, withReport, withTrace, withTimeline, withDag, process_opt, owner_id, date_created, date_modified, last_modified_user, perms)
                  VALUES ('$name', '$project_id', '$pipeline_id', '$summary', '$output_dir', '$profile', '$interdel', '$cmd', '$exec_each', '$exec_all', '$exec_all_settings', '$exec_each_settings', '$docker_check', '$docker_img', '$singu_check', '$singu_save', '$singu_img', '$exec_next_settings', '$docker_opt', '$singu_opt', '$amazon_cre_id', '$publish_dir','$publish_dir_check', '$withReport', '$withTrace', '$withTimeline', '$withDag', '$process_opt', '$ownerID', now(), now(), '$ownerID', 3)";
        return self::insTable($sql);
    }
    public function updateProjectPipeline($id, $name, $summary, $output_dir, $perms, $profile, $interdel, $cmd, $group_id, $exec_each, $exec_all, $exec_all_settings, $exec_each_settings, $docker_check, $docker_img, $singu_check, $singu_save, $singu_img, $exec_next_settings, $docker_opt, $singu_opt, $amazon_cre_id, $publish_dir, $publish_dir_check, $withReport, $withTrace, $withTimeline, $withDag, $process_opt, $ownerID) {
        $sql = "UPDATE project_pipeline SET name='$name', summary='$summary', output_dir='$output_dir', perms='$perms', profile='$profile', interdel='$interdel', cmd='$cmd', group_id='$group_id', exec_each='$exec_each', exec_all='$exec_all', exec_all_settings='$exec_all_settings', exec_each_settings='$exec_each_settings', docker_check='$docker_check', docker_img='$docker_img', singu_check='$singu_check', singu_save='$singu_save', singu_img='$singu_img', exec_next_settings='$exec_next_settings', docker_opt='$docker_opt', singu_opt='$singu_opt', amazon_cre_id='$amazon_cre_id', publish_dir='$publish_dir', publish_dir_check='$publish_dir_check', date_modified= now(), last_modified_user ='$ownerID', withReport='$withReport', withTrace='$withTrace', withTimeline='$withTimeline', withDag='$withDag',  process_opt='$process_opt' WHERE id = '$id'";
        return self::runSQL($sql);
    }

    public function getProPipeLastRunUUID($project_pipeline_id) {
        return $this->queryAVal("SELECT last_run_uuid FROM project_pipeline WHERE id='$project_pipeline_id'");
    }
    public function updateProPipeLastRunUUID($project_pipeline_id, $uuid) {
        $sql = "UPDATE project_pipeline SET last_run_uuid='$uuid' WHERE id='$project_pipeline_id'";
        return self::runSQL($sql);
    }
    public function getProjectPipelines($id,$project_id,$ownerID,$userRole) {
        if ($id != ""){
            if ($userRole == "admin"){
                $where = " where pp.id = '$id' AND pip.deleted = 0 AND pp.deleted = 0";
            } else {
                $where = " where pp.id = '$id' AND pip.deleted = 0 AND pp.deleted = 0 AND (pp.owner_id = '$ownerID' OR pp.perms = 63 OR (ug.u_id ='$ownerID' and pp.perms = 15))";
            }

            $sql = "SELECT DISTINCT pp.id, pp.name as pp_name, pip.id as pip_id, pip.rev_id, pip.name, u.username, pp.summary, pp.project_id, pp.pipeline_id, pp.date_created, pp.date_modified, pp.owner_id, p.name as project_name, pp.output_dir, pp.profile, pp.interdel, pp.group_id, pp.exec_each, pp.exec_all, pp.exec_all_settings, pp.exec_each_settings, pp.perms, pp.docker_check, pp.docker_img, pp.singu_check, pp.singu_save, pp.singu_img, pp.exec_next_settings, pp.cmd, pp.singu_opt, pp.docker_opt, pp.amazon_cre_id, pp.publish_dir, pp.publish_dir_check, pp.withReport, pp.withTrace, pp.withTimeline, pp.withDag, pp.process_opt, IF(pp.owner_id='$ownerID',1,0) as own
                      FROM project_pipeline pp
                      INNER JOIN users u ON pp.owner_id = u.id
                      INNER JOIN project p ON pp.project_id = p.id
                      INNER JOIN biocorepipe_save pip ON pip.id = pp.pipeline_id
                      LEFT JOIN user_group ug ON pp.group_id=ug.g_id
                      $where";
        } else {
            //for sidebar menu
            if ($project_id != ""){
                $sql = "SELECT DISTINCT pp.id, pp.name as pp_name, pip.id as pip_id, pip.rev_id, pip.name, u.username, pp.summary, pp.date_modified, IF(pp.owner_id='$ownerID',1,0) as own
                      FROM project_pipeline pp
                      INNER JOIN biocorepipe_save pip ON pip.id = pp.pipeline_id
                      INNER JOIN users u ON pp.owner_id = u.id
                      LEFT JOIN user_group ug ON pp.group_id=ug.g_id
                      WHERE pp.deleted = 0 AND pip.deleted = 0 AND pp.project_id = '$project_id' AND (pp.owner_id = '$ownerID' OR pp.perms = 63 OR (ug.u_id ='$ownerID' and pp.perms = 15))";
                //for run status page
            } else {
                if ($userRole == "admin"){
                    $where = " WHERE pp.deleted = 0";
                } else {
                    $where = " WHERE pp.deleted = 0 AND (pp.owner_id = '$ownerID' OR pp.perms = 63 OR (ug.u_id ='$ownerID' and pp.perms = 15))";
                }
                $sql = "SELECT DISTINCT r.id, r.project_pipeline_id, pip.name as pipeline_name, pip.id as pipeline_id, pp.name, u.email, u.username, pp.summary, r.date_modified, pp.output_dir, r.run_status, r.date_created,  r.date_ended, pp.owner_id, IF(pp.owner_id='$ownerID',1,0) as own
                      FROM run_log r
                      INNER JOIN (
                        SELECT project_pipeline_id, MAX(id) id
                        FROM run_log
                        GROUP BY project_pipeline_id
                      ) b ON r.project_pipeline_id = b.project_pipeline_id AND r.id=b.id
                      INNER JOIN project_pipeline pp ON r.project_pipeline_id = pp.id
                      INNER JOIN biocorepipe_save pip ON pip.id = pp.pipeline_id
                      INNER JOIN users u ON pp.owner_id = u.id
                      LEFT JOIN user_group ug ON pp.group_id=ug.g_id
                      $where";
            }
        }
        return self::queryTable($sql);
    }
    public function getExistProjectPipelines($pipeline_id,$ownerID) {
        $where = " where pp.deleted = 0 AND pp.pipeline_id = '$pipeline_id' AND (pp.owner_id = '$ownerID' OR pp.perms = 63 OR (ug.u_id ='$ownerID' and pp.perms = 15))";
        $sql = "SELECT DISTINCT pp.id, pp.name as pp_name, u.username, pp.date_modified, p.name as project_name
                      FROM project_pipeline pp
                      INNER JOIN users u ON pp.owner_id = u.id
                      INNER JOIN project p ON pp.project_id = p.id
                      LEFT JOIN user_group ug ON pp.group_id=ug.g_id
                      $where";
        return self::queryTable($sql);
    }
    // ------- Project Pipeline Inputs  ------
    public function insertProPipeInput($project_pipeline_id, $input_id, $project_id, $pipeline_id, $g_num, $given_name, $qualifier, $collection_id, $ownerID) {
        $sql = "INSERT INTO project_pipeline_input(collection_id, project_pipeline_id, input_id, project_id, pipeline_id, g_num, given_name, qualifier, owner_id, perms, date_created, date_modified, last_modified_user) VALUES ('$collection_id', '$project_pipeline_id', '$input_id', '$project_id', '$pipeline_id', '$g_num', '$given_name', '$qualifier', '$ownerID', 3, now(), now(), '$ownerID')";
        return self::insTable($sql);
    }
    public function updateProPipeInput($id, $project_pipeline_id, $input_id, $project_id, $pipeline_id, $g_num, $given_name, $qualifier, $collection_id, $ownerID) {
        $sql = "UPDATE project_pipeline_input SET collection_id='$collection_id', project_pipeline_id='$project_pipeline_id', input_id='$input_id', project_id='$project_id', pipeline_id='$pipeline_id', g_num='$g_num', given_name='$given_name', qualifier='$qualifier', last_modified_user ='$ownerID'  WHERE id = $id";
        return self::runSQL($sql);
    }
    public function duplicateProjectPipelineInput($new_id,$old_id,$ownerID) {
        $sql = "INSERT INTO project_pipeline_input(input_id, project_id, pipeline_id, g_num, given_name, qualifier, collection_id, project_pipeline_id, owner_id, perms, date_created, date_modified, last_modified_user)
                      SELECT input_id, project_id, pipeline_id, g_num, given_name, qualifier, collection_id, '$new_id', '$ownerID', '3', now(), now(),'$ownerID'
                      FROM project_pipeline_input
                      WHERE deleted=0 AND project_pipeline_id='$old_id'";
        return self::insTable($sql);
    }
    public function duplicateProcess($new_process_gid, $new_name, $old_id, $ownerID) {
        $sql = "INSERT INTO process(process_uuid, process_rev_uuid, process_group_id, name, summary, script, script_header, script_footer, script_mode, script_mode_header, owner_id, perms, date_created, date_modified, last_modified_user, rev_id, process_gid)
                      SELECT '', '', process_group_id, '$new_name', summary, script, script_header, script_footer, script_mode, script_mode_header, '$ownerID', '3', now(), now(),'$ownerID', '0', '$new_process_gid'
                      FROM process
                      WHERE id='$old_id'";
        return self::insTable($sql);
    }
    public function createProcessRev($new_process_gid, $rev_comment, $rev_id, $old_id, $ownerID) {
        $sql = "INSERT INTO process(process_uuid, process_rev_uuid, process_group_id, name, summary, script, script_header, script_footer, script_mode, script_mode_header, owner_id, perms, date_created, date_modified, last_modified_user, rev_id, process_gid, rev_comment)
                      SELECT process_uuid, '', process_group_id, name, summary, script, script_header, script_footer, script_mode, script_mode_header, '$ownerID', '3', now(), now(),'$ownerID', '$rev_id', '$new_process_gid', '$rev_comment'
                      FROM process
                      WHERE id='$old_id'";
        return self::insTable($sql);
    }
    public function duplicateProcessParameter($new_pro_id, $old_id, $ownerID){
        $sql = "INSERT INTO process_parameter(process_id, parameter_id, type, sname, operator, closure, reg_ex, optional, owner_id, perms, date_created, date_modified, last_modified_user)
                      SELECT '$new_pro_id', parameter_id, type, sname, operator, closure, reg_ex, optional, '$ownerID', '3', now(), now(),'$ownerID'
                      FROM process_parameter
                      WHERE process_id='$old_id'";
        return self::insTable($sql);
    }
    public function getCollectionFiles($collection_id,$ownerID) {
        $where = " where f.deleted=0 AND fc.deleted=0 AND fc.c_id = '$collection_id' AND (f.owner_id = '$ownerID' OR f.perms = 63 OR (ug.u_id ='$ownerID' and f.perms = 15))";
        $sql = "SELECT DISTINCT f.*
                      FROM file f
                      INNER JOIN file_collection fc ON f.id=fc.f_id
                      LEFT JOIN user_group ug ON f.group_id=ug.g_id
                      $where";
        return self::queryTable($sql);
    }
    public function getProjectPipelineInputs($project_pipeline_id,$ownerID) {
        $where = " where ppi.deleted=0 AND ppi.project_pipeline_id = '$project_pipeline_id' AND (ppi.owner_id = '$ownerID' OR ppi.perms = 63 OR (ug.u_id ='$ownerID' and ppi.perms = 15))";
        $sql = "SELECT DISTINCT ppi.id, i.id as input_id, i.name, ppi.given_name, ppi.g_num, ppi.collection_id, c.name as collection_name
                      FROM project_pipeline_input ppi
                      LEFT JOIN input i ON i.id = ppi.input_id
                      LEFT JOIN collection c ON c.id = ppi.collection_id
                      LEFT JOIN user_group ug ON ppi.group_id=ug.g_id
                      $where";
        return self::queryTable($sql);
    }
    public function getProjectPipelineInputsById($id,$ownerID) {
        $where = " where ppi.deleted=0 AND ppi.id= '$id' AND (ppi.owner_id = '$ownerID' OR ppi.perms = 63)" ;
        $sql = "SELECT ppi.id, ppi.qualifier, i.id as input_id, i.name, ppi.collection_id, c.name as collection_name
                      FROM project_pipeline_input ppi
                      LEFT JOIN input i ON i.id = ppi.input_id
                      LEFT JOIN collection c ON c.id = ppi.collection_id
                      $where";
        return self::queryTable($sql);
    }
    public function insertProcessParameter($sname, $process_id, $parameter_id, $type, $closure, $operator, $reg_ex, $optional, $perms, $group_id, $ownerID) {
        $sql = "INSERT INTO process_parameter(sname, process_id, parameter_id, type, closure, operator, reg_ex, optional, owner_id, date_created, date_modified, last_modified_user, perms, group_id)
                      VALUES ('$sname', '$process_id', '$parameter_id', '$type', '$closure', '$operator', '$reg_ex', '$optional', '$ownerID', now(), now(), '$ownerID', '$perms', '$group_id')";
        return self::insTable($sql);
    }

    public function updateProcessParameter($id, $sname, $process_id, $parameter_id, $type, $closure, $operator, $reg_ex, $optional, $perms, $group_id, $ownerID) {
        $sql = "UPDATE process_parameter SET sname='$sname', process_id='$process_id', parameter_id='$parameter_id', type='$type', closure='$closure', operator='$operator', reg_ex='$reg_ex', optional='$optional', last_modified_user ='$ownerID', perms='$perms', group_id='$group_id'  WHERE id = '$id'";
        return self::runSQL($sql);
    }

    public function removeProcessParameter($id) {
        $sql = "DELETE FROM process_parameter WHERE id = '$id'";
        return self::runSQL($sql);
    }

    public function removeProcessParameterByParameterID($parameter_id) {
        $sql = "DELETE FROM process_parameter WHERE parameter_id = '$parameter_id'";
        return self::runSQL($sql);
    }
    public function removeProcessParameterByProcessGroupID($process_group_id) {
        $sql = "DELETE process_parameter
                      FROM process_parameter
                      JOIN process ON process.id = process_parameter.process_id
                      WHERE process.process_group_id = '$process_group_id'";
        return self::runSQL($sql);
    }
    public function removeProcessParameterByProcessID($process_id) {
        $sql = "DELETE FROM process_parameter WHERE process_id = '$process_id'";
        return self::runSQL($sql);
    }
    //------- feedback ------
    public function savefeedback($email,$message,$url) {
        $email = str_replace("'", "''", $email);
        $sql = "INSERT INTO feedback(email, message, url, date_created) VALUES
                      ('$email', '$message','$url', now())";
        return self::insTable($sql);
    }

    public function sendEmail($from, $from_name, $to, $subject, $message) {
        $message = str_replace("\n","<br>",$message);
        $message = wordwrap($message, 70);
        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
        $headers .= 'From: '.$from_name.' <'.$from.'>' . "\r\n";
        $ret = array();
        if(@mail($to, $subject, $message, $headers)){
            $ret['status'] = "sent";
        } else{
            $ret['status'] = "failed";
        }
        return json_encode($ret);
    }
    // --------- Pipeline -----------
    public function getPipelineGroup($ownerID) {
        $sql = "SELECT pg.id, pg.group_name
                      FROM pipeline_group pg";
        return self::queryTable($sql);
    }
    public function insertPipelineGroup($group_name, $ownerID) {
        $sql = "INSERT INTO pipeline_group (owner_id, group_name, date_created, date_modified, last_modified_user, perms) VALUES ('$ownerID', '$group_name', now(), now(), '$ownerID', 63)";
        return self::insTable($sql);
    }
    public function updatePipelineGroup($id, $group_name, $ownerID) {
        $sql = "UPDATE pipeline_group SET group_name='$group_name', last_modified_user ='$ownerID', date_modified=now()  WHERE id = '$id'";
        return self::runSQL($sql);
    }

    public function getEditDelPipelineGroups($ownerID) {
        $sql = "SELECT DISTINCT id, group_name
                      FROM pipeline_group pg
                      Where pg.owner_id = '$ownerID' AND id not in (SELECT pipeline_group_id FROM biocorepipe_save WHERE owner_id != '$ownerID' AND deleted = 0)";
        return self::queryTable($sql);
    }

    public function getPublicPipelines() {
        $sql= "SELECT pip.id, pip.name, pip.summary, pip.pin, pip.pin_order, pip.script_pipe_header, pip.script_pipe_footer, pip.script_mode_header, pip.script_mode_footer, pip.pipeline_group_id
                      FROM biocorepipe_save pip
                      INNER JOIN (
                        SELECT pipeline_gid, MAX(rev_id) rev_id
                        FROM biocorepipe_save
                        WHERE pin = 'true' AND perms = 63
                        GROUP BY pipeline_gid
                        ) b ON pip.rev_id = b.rev_id AND pip.pipeline_gid=b.pipeline_gid AND pip.deleted = 0";
        return self::queryTable($sql);
    }
    public function getProcessData($ownerID) {
        if ($ownerID == ""){
            $ownerID ="''";
        } else {
            $userRoleCheck = $this->getUserRole($ownerID);
            if (isset(json_decode($userRoleCheck)[0])){
                $userRole = json_decode($userRoleCheck)[0]->{'role'};
                if ($userRole == "admin"){
                    $sql = "SELECT DISTINCT p.id, p.process_group_id, p.name, p.summary, p.script, p.script_header, p.script_footer, p.script_mode, p.script_mode_header, p.rev_id, p.perms, p.group_id, p.publish, IF(p.owner_id='$ownerID',1,0) as own FROM process p ";
                    return self::queryTable($sql);
                }
            }
        }
        $sql = "SELECT DISTINCT p.id, p.process_group_id, p.name, p.summary, p.script, p.script_header, p.script_footer, p.script_mode, p.script_mode_header, p.rev_id, p.perms, p.group_id, p.publish, IF(p.owner_id='$ownerID',1,0) as own
                        FROM process p
                        LEFT JOIN user_group ug ON p.group_id=ug.g_id
                        WHERE p.owner_id = '$ownerID' OR p.perms = 63 OR (ug.u_id ='$ownerID' and p.perms = 15)";
        return self::queryTable($sql);
    }
    public function getLastProPipeByUUID($id, $type, $ownerID) {
        if ($type == "process"){
            $table = "process";
        } else if ($type == "pipeline"){
            $table = "biocorepipe_save";
        }
        if ($ownerID != ''){
            $userRoleArr = json_decode($this->getUserRole($ownerID));
            $userRole = $userRoleArr[0]->{'role'};
            if ($userRole == "admin"){
                $sql="SELECT DISTINCT p.*, pg.group_name as process_group_name
                            FROM $table p
                            INNER JOIN {$type}_group pg ON p.{$type}_group_id = pg.id
                            INNER JOIN (
                              SELECT pr.{$type}_gid, MAX(pr.rev_id) rev_id
                              FROM $table pr
                              GROUP BY pr.{$type}_gid
                              ) b ON p.rev_id = b.rev_id AND p.{$type}_gid=b.{$type}_gid AND p.deleted = 0 AND p.{$type}_uuid = '$id'";
                return self::queryTable($sql);
            }
            $where_pg = "(pg.owner_id='$ownerID' OR pg.perms = 63 OR (ug.u_id ='$ownerID' and pg.perms = 15))";
            $where_pr = "(pr.owner_id='$ownerID' OR pr.perms = 63 OR (ug.u_id ='$ownerID' and pr.perms = 15))";
        }
        $sql="SELECT DISTINCT p.*, pg.group_name as {$type}_group_name
                          FROM $table p
                          LEFT JOIN user_group ug ON  p.group_id=ug.g_id
                          INNER JOIN {$type}_group pg
                          ON p.{$type}_group_id = pg.id and p.{$type}_uuid = '$id' AND $where_pg
                          INNER JOIN (
                            SELECT pr.{$type}_gid, MAX(pr.rev_id) rev_id
                            FROM $table pr
                            LEFT JOIN user_group ug ON pr.group_id=ug.g_id where $where_pr
                            GROUP BY pr.{$type}_gid
                            ) b ON p.rev_id = b.rev_id AND p.{$type}_gid=b.{$type}_gid AND p.deleted = 0";

        return self::queryTable($sql);
    }
    public function getProPipeDataByUUID($uuid, $rev_uuid, $type, $ownerID) {
        if ($type == "process"){
            $table = "process";
        } else if ($type == "pipeline"){
            $table = "biocorepipe_save";
        }
        if ($ownerID == ""){
            $ownerID ="''";
        }else {
            $userRoleCheck = $this->getUserRole($ownerID);
            if (isset(json_decode($userRoleCheck)[0])){
                $userRole = json_decode($userRoleCheck)[0]->{'role'};
                if ($userRole == "admin"){
                    $sql = "SELECT DISTINCT p.*, u.username, pg.group_name as {$type}_group_name, IF(p.owner_id='$ownerID',1,0) as own
                                  FROM $table p
                                  INNER JOIN users u ON p.owner_id = u.id
                                  INNER JOIN {$type}_group pg ON p.{$type}_group_id = pg.id
                                  where p.deleted = 0 AND p.{$type}_rev_uuid = '$rev_uuid' AND p.{$type}_uuid = '$uuid' ";
                    return self::queryTable($sql);
                }
            }
        }
        $sql = "SELECT DISTINCT p.*, u.username, pg.group_name as {$type}_group_name, IF(p.owner_id='$ownerID',1,0) as own
                            FROM $table p
                            LEFT JOIN user_group ug ON p.group_id=ug.g_id
                            INNER JOIN users u ON p.owner_id = u.id
                            INNER JOIN {$type}_group pg ON p.{$type}_group_id = pg.id
                            where p.{$type}_rev_uuid = '$rev_uuid' AND p.{$type}_uuid = '$uuid' AND p.deleted = 0 AND (p.owner_id = '$ownerID' OR p.perms = 63 OR (ug.u_id ='$ownerID' and p.perms = 15))";
        return self::queryTable($sql);
    }


    public function getProcessDataById($id, $ownerID) {
        if ($ownerID == ""){
            $ownerID ="''";
        }else {
            $userRoleCheck = $this->getUserRole($ownerID);
            if (isset(json_decode($userRoleCheck)[0])){
                $userRole = json_decode($userRoleCheck)[0]->{'role'};
                if ($userRole == "admin"){
                    $sql = "SELECT DISTINCT p.*, u.username, pg.group_name as process_group_name, IF(p.owner_id='$ownerID',1,0) as own
                                  FROM process p
                                  INNER JOIN users u ON p.owner_id = u.id
                                  INNER JOIN process_group pg ON p.process_group_id = pg.id
                                  where p.id = '$id'";
                    return self::queryTable($sql);
                }
            }
        }
        $sql = "SELECT DISTINCT p.*, u.username, pg.group_name as process_group_name, IF(p.owner_id='$ownerID',1,0) as own
                            FROM process p
                            LEFT JOIN user_group ug ON p.group_id=ug.g_id
                            INNER JOIN users u ON p.owner_id = u.id
                            INNER JOIN process_group pg ON p.process_group_id = pg.id
                            where p.id = '$id' AND (p.owner_id = '$ownerID' OR p.perms = 63 OR (ug.u_id ='$ownerID' and p.perms = 15))";
        return self::queryTable($sql);
    }
    public function getProcessRevision($process_gid,$ownerID) {
        if ($ownerID != ""){
            $userRoleCheck = $this->getUserRole($ownerID);
            if (isset(json_decode($userRoleCheck)[0])){
                $userRole = json_decode($userRoleCheck)[0]->{'role'};
                if ($userRole == "admin"){
                    $sql = "SELECT DISTINCT p.id, p.rev_id, p.rev_comment, p.last_modified_user, p.date_created, p.date_modified, IF(p.owner_id='$ownerID',1,0) as own
                                  FROM process p
                                  WHERE p.process_gid = '$process_gid'";
                    return self::queryTable($sql);
                }
            }
        }
        $sql = "SELECT DISTINCT p.id, p.rev_id, p.rev_comment, p.last_modified_user, p.date_created, p.date_modified, IF(p.owner_id='$ownerID',1,0) as own
                            FROM process p
                            LEFT JOIN user_group ug ON p.group_id=ug.g_id
                            WHERE p.process_gid = '$process_gid' AND (p.owner_id = '$ownerID' OR p.perms = 63 OR (ug.u_id ='$ownerID' and p.perms = 15))";
        return self::queryTable($sql);
    }
    public function getPipelineRevision($pipeline_gid,$ownerID) {
        if ($ownerID != ""){
            $userRoleCheck = $this->getUserRole($ownerID);
            if (isset(json_decode($userRoleCheck)[0])){
                $userRole = json_decode($userRoleCheck)[0]->{'role'};
                if ($userRole == "admin"){
                    $sql = "SELECT DISTINCT pip.id, pip.rev_id, pip.rev_comment, pip.last_modified_user, pip.date_created, pip.date_modified, IF(pip.owner_id='$ownerID',1,0) as own, pip.perms FROM biocorepipe_save pip WHERE pip.deleted = 0 AND pip.pipeline_gid = '$pipeline_gid'";
                    return self::queryTable($sql);
                }
            }
        }
        $sql = "SELECT DISTINCT pip.id, pip.rev_id, pip.rev_comment, pip.last_modified_user, pip.date_created, pip.date_modified, IF(pip.owner_id='$ownerID',1,0) as own, pip.perms
                            FROM biocorepipe_save pip
                            LEFT JOIN user_group ug ON pip.group_id=ug.g_id
                            WHERE pip.deleted = 0 AND pip.pipeline_gid = '$pipeline_gid' AND (pip.owner_id = '$ownerID' OR pip.perms = 63 OR (ug.u_id ='$ownerID' and pip.perms = 15))";
        return self::queryTable($sql);
    }

    public function getInputsPP($id) {
        $sql = "SELECT pp.parameter_id, pp.sname, pp.id, pp.operator, pp.closure, pp.reg_ex, pp.optional, p.name, p.file_type, p.qualifier
                            FROM process_parameter pp
                            INNER JOIN parameter p ON pp.parameter_id = p.id
                            WHERE pp.process_id = '$id' and pp.type = 'input'";
        return self::queryTable($sql);
    }
    public function checkPipeline($process_id, $ownerID) {
        $sql = "SELECT id, name FROM biocorepipe_save WHERE deleted = 0 AND owner_id = '$ownerID' AND nodes LIKE '%\"$process_id\",\"%'";
        return self::queryTable($sql);
    }
    public function checkInput($name,$type) {
        $sql = "SELECT id, name FROM input WHERE name = '$name' AND type='$type'";
        return self::queryTable($sql);
    }
    public function checkProjectInput($project_id, $input_id) {
        $sql = "SELECT id FROM project_input WHERE input_id = '$input_id' AND project_id = '$project_id'";
        return self::queryTable($sql);
    }
    public function checkFileProject($project_id, $file_id) {
        $sql = "SELECT id FROM file_project WHERE deleted=0 AND f_id = '$file_id' AND p_id = '$project_id'";
        return self::queryTable($sql);
    }
    public function checkProPipeInput($project_id, $input_id, $pipeline_id, $project_pipeline_id) {
        $sql = "SELECT id FROM project_pipeline_input WHERE deleted =0 AND input_id = '$input_id' AND project_id = '$project_id' AND pipeline_id = '$pipeline_id' AND project_pipeline_id = '$project_pipeline_id'";
        return self::queryTable($sql);
    }
    public function checkPipelinePublic($process_id, $ownerID) {
        $sql = "SELECT id, name FROM biocorepipe_save WHERE deleted = 0 AND owner_id != '$ownerID' AND nodes LIKE '%\"$process_id\",\"%'";
        return self::queryTable($sql);
    }
    public function checkProjectPipelinePublic($process_id, $ownerID) {
        $sql = "SELECT DISTINCT p.id, p.name
                            FROM biocorepipe_save p
                            INNER JOIN project_pipeline pp ON p.id = pp.pipeline_id
                            WHERE p.deleted = 0 AND pp.deleted = 0 AND (pp.owner_id != '$ownerID') AND p.nodes LIKE '%\"$process_id\",\"%'";
        return self::queryTable($sql);
    }
    public function checkPipelinePerm($process_id) {
        $sql = "SELECT id, name FROM biocorepipe_save WHERE deleted = 0 AND perms>3 AND nodes LIKE '%\"$process_id\",\"%'";
        return self::queryTable($sql);
    }
    public function checkProjectPipePerm($pipeline_id) {
        $sql = "SELECT id, name FROM project_pipeline WHERE deleted = 0 && perms>3 AND pipeline_id='$pipeline_id'";
        return self::queryTable($sql);
    }
    public function checkParameter($parameter_id, $ownerID) {
        $sql = "SELECT DISTINCT pp.id, p.name
                            FROM process_parameter pp
                            INNER JOIN process p ON pp.process_id = p.id
                            WHERE (pp.owner_id = '$ownerID') AND pp.parameter_id = '$parameter_id'";
        return self::queryTable($sql);
    }
    public function checkMenuGr($id) {
        $sql = "SELECT DISTINCT pg.id, p.name
                            FROM process p
                            INNER JOIN process_group pg ON p.process_group_id = pg.id
                            WHERE pg.id = '$id'";
        return self::queryTable($sql);
    }
    public function checkPipeMenuGr($id) {
        $sql = "SELECT DISTINCT pg.id, p.name
                            FROM biocorepipe_save p
                            INNER JOIN pipeline_group pg ON p.pipeline_group_id = pg.id
                            WHERE p.deleted = 0 AND pg.id = '$id'";
        return self::queryTable($sql);
    }
    public function checkProject($pipeline_id, $ownerID) {
        $sql = "SELECT DISTINCT pp.id, p.name
                            FROM project_pipeline pp
                            INNER JOIN project p ON pp.project_id = p.id
                            WHERE pp.deleted = 0 AND pp.owner_id = '$ownerID' AND pp.pipeline_id = '$pipeline_id'";
        return self::queryTable($sql);
    }
    public function checkProjectPublic($pipeline_id, $ownerID) {
        $sql = "SELECT DISTINCT pp.id, p.name
                            FROM project_pipeline pp
                            INNER JOIN project p ON pp.project_id = p.id
                            WHERE pp.deleted = 0 AND pp.owner_id != '$ownerID' AND pp.pipeline_id = '$pipeline_id'";
        return self::queryTable($sql);
    }
    public function getMaxProcess_gid() {
        $sql = "SELECT MAX(process_gid) process_gid FROM process";
        return self::queryTable($sql);
    }
    public function getMaxPipeline_gid() {
        $sql = "SELECT MAX(pipeline_gid) pipeline_gid FROM biocorepipe_save WHERE deleted = 0";
        return self::queryTable($sql);
    }
    public function getProcess_gid($process_id) {
        $sql = "SELECT process_gid FROM process WHERE id = '$process_id'";
        return self::queryTable($sql);
    }
    public function getProcess_uuid($process_id) {
        $sql = "SELECT process_uuid FROM process WHERE id = '$process_id'";
        return self::queryTable($sql);
    }
    public function getPipeline_gid($pipeline_id) {
        $sql = "SELECT pipeline_gid FROM biocorepipe_save WHERE id = '$pipeline_id'";
        return self::queryTable($sql);
    }
    public function getPipeline_uuid($pipeline_id) {
        $sql = "SELECT pipeline_uuid FROM biocorepipe_save WHERE deleted = 0 AND id = '$pipeline_id'";
        return self::queryTable($sql);
    }
    public function getMaxRev_id($process_gid) {
        $sql = "SELECT MAX(rev_id) rev_id FROM process WHERE process_gid = '$process_gid'";
        return self::queryTable($sql);
    }
    public function getMaxPipRev_id($pipeline_gid) {
        $sql = "SELECT MAX(rev_id) rev_id FROM biocorepipe_save WHERE deleted = 0 AND pipeline_gid = '$pipeline_gid'";
        return self::queryTable($sql);
    }
    public function getOutputsPP($id) {
        $sql = "SELECT pp.parameter_id, pp.sname, pp.id, pp.operator, pp.closure, pp.reg_ex, pp.optional, p.name, p.file_type, p.qualifier
                            FROM process_parameter pp
                            INNER JOIN parameter p ON pp.parameter_id = p.id
                            WHERE pp.process_id = '$id' and pp.type = 'output'";
        return self::queryTable($sql);
    }
    //update if user owns the project
    public function updateProjectGroupPerm($id, $group_id, $perms, $ownerID) {
        $sql = "UPDATE project p
                            INNER JOIN project_pipeline pp ON p.id=pp.project_id
                            SET p.group_id='$group_id', p.perms='$perms', p.date_modified=now(), p.last_modified_user ='$ownerID'  WHERE pp.id = '$id' AND p.perms <= '$perms'";
        return self::runSQL($sql);
    }

    public function updateProjectInputGroupPerm($id, $group_id, $perms, $ownerID) {
        $sql = "UPDATE project_input pi
                            INNER JOIN project_pipeline_input ppi ON pi.input_id=ppi.input_id
                            SET pi.group_id='$group_id', pi.perms='$perms', pi.date_modified=now(), pi.last_modified_user ='$ownerID'  WHERE ppi.deleted=0 AND ppi.project_pipeline_id = '$id' and pi.perms <= '$perms'";
        return self::runSQL($sql);
    }

    public function updateProjectPipelineInputGroupPerm($id, $group_id, $perms, $ownerID) {
        $sql = "UPDATE project_pipeline_input SET group_id='$group_id', perms='$perms', date_modified=now(), last_modified_user ='$ownerID'  WHERE deleted=0 AND project_pipeline_id = '$id' AND perms <= '$perms'";
        return self::runSQL($sql);
    }

    public function updatePipelineGroupPerm($id, $group_id, $perms, $ownerID) {
        $sql = "UPDATE biocorepipe_save pi
                            INNER JOIN project_pipeline_input ppi ON pi.id=ppi.pipeline_id
                            SET pi.group_id='$group_id', pi.perms='$perms', pi.date_modified=now(), pi.last_modified_user ='$ownerID'  WHERE pi.deleted=0 AND ppi.deleted=0 AND ppi.project_pipeline_id = '$id' AND pi.perms <= '$perms'";
        return self::runSQL($sql);
    }

    public function updatePipelineGroupPermByPipeId($id, $group_id, $perms, $ownerID) {
        $sql = "UPDATE biocorepipe_save pi
                            SET pi.group_id='$group_id', pi.perms='$perms', pi.date_modified=now(), pi.last_modified_user ='$ownerID'  WHERE pi.deleted=0 AND pi.id = '$id' AND pi.perms <= '$perms'";
        return self::runSQL($sql);
    }

    public function updatePipelineProcessGroupPerm($id, $group_id, $perms, $ownerID) {
        $sql = "SELECT pip.nodes
                            FROM biocorepipe_save pip
                            INNER JOIN project_pipeline_input pi ON pip.id=pi.pipeline_id
                            WHERE pi.deleted=0 AND pip.deleted=0 AND pi.project_pipeline_id = '$id' and pi.owner_id='$ownerID'";
        $nodesArr = json_decode(self::queryTable($sql));
        if (!empty($nodesArr[0])){
            $nodes = json_decode($nodesArr[0]->{"nodes"});
            foreach ($nodes as $item):
            if ($item[2] !== "inPro" && $item[2] !== "outPro"){
                $proId = $item[2];
                $this->updateProcessGroupPerm($proId, $group_id, $perms, $ownerID);
                $this->updateProcessParameterGroupPerm($proId, $group_id, $perms, $ownerID);
            }
            endforeach;
        }
    }

    //update if user owns the process
    public function updateProcessGroupPerm($id, $group_id, $perms, $ownerID) {
        $sql = "UPDATE process SET group_id='$group_id', perms='$perms', date_modified=now(), last_modified_user ='$ownerID'  WHERE id = '$id' and  perms <= '$perms'";
        return self::runSQL($sql);
    }

    public function updateProcessParameterGroupPerm($id, $group_id, $perms, $ownerID) {
        $sql = "UPDATE process_parameter SET group_id='$group_id', perms='$perms', date_modified=now(), last_modified_user ='$ownerID'  WHERE process_id = '$id' AND perms <= '$perms'";
        return self::runSQL($sql);
    }

    public function updatePipelinePerms($nodesRaw, $group_id, $perms, $ownerID) {
        foreach ($nodesRaw as $item):
        if ($item[2] !== "inPro" && $item[2] !== "outPro" ){
            //pipeline modules
            if (preg_match("/p(.*)/", $item[2], $matches)){
                $pipeModId = $matches[1];
                if (!empty($pipeModId)){
                    settype($pipeModId, "integer");
                    $this->updatePipelineGroupPermByPipeId($pipeModId, $group_id, $perms, $ownerID);
                }
                //processes
            } else {
                $proId = $item[2];
                $this->updateProcessGroupPerm($proId, $group_id, $perms, $ownerID);
                $this->updateProcessParameterGroupPerm($proId, $group_id, $perms, $ownerID);
            }
        }
        endforeach;
    }

    public function updateUUID ($id, $type, $res){
        $update = "";
        if ($type == "process"){
            $table = "process";
            if (!empty($res->uuid) && !empty($res->rev_uuid)){
                $update = "process_uuid='$res->uuid', process_rev_uuid='$res->rev_uuid'";
            }
        } else if ($type == "process_rev"){
            $table = "process";
            if (!empty($res->rev_uuid)){
                $update = "process_rev_uuid='$res->rev_uuid'";
            }
        } else if ($type == "pipeline"){
            $table = "biocorepipe_save";
            if (!empty($res->uuid) && !empty($res->rev_uuid)){
                $update = "pipeline_uuid='$res->uuid', pipeline_rev_uuid='$res->rev_uuid'";
            }
        } else if ($type == "pipeline_rev"){
            $table = "biocorepipe_save";
            if (!empty($res->rev_uuid)){
                $update = "pipeline_rev_uuid='$res->rev_uuid'";
            }
        } else if ($type == "run_log"){
            $table = "run_log";
            if (!empty($res->rev_uuid)){
                $update = "run_log_uuid='$res->rev_uuid'";
                $targetDir = "{$this->tmp_path}/api";
                if (!file_exists($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }
            }
        }
        $sql = "UPDATE $table SET $update  WHERE id = '$id'";
        return self::runSQL($sql);
    }

    public function getUUIDLocal($type){
        $params=[];
        $params["type"]=$type;
        $myClass = new funcs();
        $res= (object)$myClass->getUUID($params);
        return $res;
    }

    public function moveFile($type, $from, $to, $ownerID){
        $res = false;
        if ($type == "pubweb"){
            $from = "{$this->run_path}/$from";
            $to = "{$this->run_path}/$to";
        }
        if (file_exists($from)) {
            $res = rename($from, $to);
        }

        return json_encode($res);
    }

    public function tsvConvert($tsv, $format){
        $tsv = trim($tsv);
        $lines = explode("\n", $tsv);
        $header = explode("\t", $lines[0]);
        $data = array();
        for ($i = 1; $i < count($lines); $i++) {
            $obj = new stdClass();
            $currentline = explode("\t", $lines[$i]);
            for ($j = 0; $j < count($header); $j++) {
                $name = $header[$j];
                $obj->$name = $currentline[$j];
            }
            $data[] = $obj;
        }
        return $data;
    }


    public function callDebrowser($uuid, $dir, $filename){
        $targetDir = "{$this->run_path}/$uuid/pubweb/$dir";
        $targetFile = "{$targetDir}/{$filename}";
        $targetJson = "{$targetDir}/.{$filename}";
        $tsv= file_get_contents($targetFile);
        $array = $this->tsvConvert($tsv, "json");
        file_put_contents($targetJson, json_encode($array));
        return json_encode("$dir/.{$filename}");
    }
    public function callRmarkdown($type, $uuid, $text, $dir, $filename){
        //travis fix
        if (!headers_sent()) {
            ob_start();
            // send $data to user
            $targetDir = "{$this->run_path}/$uuid/pubweb/$dir/.tmp";
            $errorCheck = false;
            $errorText = "";
            if (!file_exists($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            $format = "";
            if ($type == "rmdtext"){
                $format = "html";
            } else if ($type == "rmdpdf"){
                $format = "pdf";
            }
            $pUUID = uniqid();
            $log = "{$targetDir}/{$filename}.log{$pUUID}";
            $response = "{$targetDir}/{$filename}.curl{$pUUID}";
            $file = "{$targetDir}/{$filename}.{$format}{$pUUID}";
            $err = "{$targetDir}/{$filename}.{$format}.err{$pUUID}";
            $url =  OCPU_URL."/ocpu/library/markdownapp/R/".$type;
            $cmd = "(curl '$url' -H \"Content-Type: application/json\" -k -d '{\"text\":$text}' -o $response > $log 2>&1) & echo \$!";
            $pid = exec($cmd);
            $data = json_encode($pUUID);
            if (!headers_sent()) {
                header('Cache-Control: no-cache, must-revalidate');
                header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
                header('Content-type: application/json');
                echo $data;
            } else {
                echo $data;
            }
            //function returned at this point for user
            $size = ob_get_length();
            header("Content-Encoding: none");
            header("Content-Length: {$size}");
            header("Connection: close");
            ob_end_flush();
            ob_flush();
            flush();
        }
        //server side keeps working
        if (!empty($pUUID)){
            for( $i= 0 ; $i < 100 ; $i++ ){
                sleep(1);
                $resText = $this->readFile($response);
                if (!empty($resText)){
                    unlink($response);
                    break;
                }
                if ($i <5){
                    sleep(1);
                } else {
                    sleep(4);
                }
            }
            $ret = "";
            if (!empty($resText)){
                $lines = explode("\n", $resText);
                foreach ($lines as $lin):
                if ($type == "rmdtext" && preg_match("/.*output.html/", $lin, $matches)){
                    $ret = OCPU_URL.$lin;
                    break;
                } else if ($type == "rmdpdf" && preg_match("/.*output.pdf/", $lin, $matches)){
                    $ret = OCPU_URL.$lin;
                    break;
                }
                endforeach;

                if (empty($ret)){
                    $errorCheck =true;
                    $errorText = $resText;
                }
                if (!empty($ret)){
                    if (file_exists($file)) {
                        unlink($file);
                    }
                    if (file_exists($err)) {
                        unlink($err);
                    }
                    exec("curl '$ret' -o \"{$file}\" > /dev/null 2>&1 &", $res, $exit);
                } else {
                    $errorCheck =true;
                }
            } else {
                $errorCheck =true;
                $errorText = "Timeout error";
            }
            if ($errorCheck == true){
                $fp = fopen($err, 'w');
                fwrite($fp, $errorText);
                fclose($fp);
            }
        }
    }

    public function getUUIDAPI($data,$type,$id){
        //travis fix
        if (!headers_sent()) {
            ob_start();
            // send $data to user
            if (!headers_sent()) {
                header('Cache-Control: no-cache, must-revalidate');
                header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
                header('Content-type: application/json');
                echo $data;
            } else {
                echo $data;
            }
            //function returned at this point for user
            $size = ob_get_length();
            header("Content-Encoding: none");
            header("Content-Length: {$size}");
            header("Connection: close");
            ob_end_flush();
            ob_flush();
            flush();
        }
        //server side keeps working
        $targetDir = "{$this->tmp_path}/api";
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $uuidPath = "{$targetDir}/{$type}{$id}.txt";
        $request = CENTRAL_API_URL."/api/service.php?func=getUUID&type=$type";
        exec("curl '$request' -o $uuidPath > /dev/null 2>&1 &", $res, $exit);
        for( $i= 0 ; $i < 4 ; $i++ ){
            sleep(5);
            $uuidFile = $this->readFile($uuidPath);
            if (!empty($uuidFile)){
                $res = json_decode($uuidFile);
                unlink($uuidPath);
                break;
            }
        }
        if (!isset($res->rev_uuid)){
            //call local functions to get uuid
            $params=[];
            $params["type"]=$type;
            $myClass = new funcs();
            $res= (object)$myClass->getUUID($params);
            if (isset($res->rev_uuid)){
                $this->updateUUID($id, $type, $res);
            }
        } else {
            $this->updateUUID($id, $type, $res);
        }
    }


    public function convert_array_to_obj_recursive($a) {
        if (is_array($a) ) {
            foreach($a as $k => $v) {
                if (is_integer($k)) {
                    // only need this if you want to keep the array indexes separate
                    // from the object notation: eg. $o->{1}
                    $a['index'][$k] = $this->convert_array_to_obj_recursive($v);
                }
                else {
                    $a[$k] = $this->convert_array_to_obj_recursive($v);
                }
            }

            return (object) $a;
        }

        // else maintain the type of $a
        return $a;
    }


    //if you add new field here, please consider import/export functionality(import.js - itemOrder)
    public function saveAllPipeline($dat,$ownerID) {
        $obj = json_decode($dat);
        $newObj = new stdClass();
        foreach ($obj as $item):
        foreach($item as $k => $v) $newObj->$k = $v;
        endforeach;
        $name =  $newObj->{"name"};
        $id = $newObj->{"id"};
        $nodes = json_encode($newObj->{"nodes"});
        $mainG = "{\'mainG\':".json_encode($newObj->{"mainG"})."}";
        $edges = "{\'edges\':".json_encode($newObj->{"edges"})."}";
        $summary = addslashes(htmlspecialchars(urldecode($newObj->{"summary"}), ENT_QUOTES));
        $group_id = $newObj->{"group_id"};
        $perms = $newObj->{"perms"};
        $pin = $newObj->{"pin"};
        $pin_order = $newObj->{"pin_order"};
        $publish = $newObj->{"publish"};
        $script_pipe_header = addslashes(htmlspecialchars(urldecode($newObj->{"script_pipe_header"}), ENT_QUOTES));
        $script_pipe_footer = addslashes(htmlspecialchars(urldecode($newObj->{"script_pipe_footer"}), ENT_QUOTES));
        $script_mode_header = $newObj->{"script_mode_header"};
        $script_mode_footer = $newObj->{"script_mode_footer"};
        $pipeline_group_id = $newObj->{"pipeline_group_id"};
        $process_list = $newObj->{"process_list"};
        $pipeline_list = $newObj->{"pipeline_list"};
        $publish_web_dir = $newObj->{"publish_web_dir"};
        $pipeline_gid = isset($newObj->{"pipeline_gid"}) ? $newObj->{"pipeline_gid"} : "";
        if (empty($pipeline_gid)) {
            $max_gid = json_decode($this->getMaxPipeline_gid(),true)[0]["pipeline_gid"];
            settype($max_gid, "integer");
            if (!empty($max_gid) && $max_gid != 0) {
                $pipeline_gid = $max_gid +1;
            } else {
                $pipeline_gid = 1;
            }
        }
        $rev_comment = isset($newObj->{"rev_comment"}) ? $newObj->{"rev_comment"} : "";
        $rev_id = isset($newObj->{"rev_id"}) ? $newObj->{"rev_id"} : "";
        $pipeline_uuid = isset($newObj->{"pipeline_uuid"}) ? $newObj->{"pipeline_uuid"} : "";
        $pipeline_rev_uuid = isset($newObj->{"pipeline_rev_uuid"}) ? $newObj->{"pipeline_rev_uuid"} : "";
        settype($pipeline_group_id, "integer");
        settype($rev_id, "integer");
        settype($pipeline_gid, "integer");
        settype($perms, "integer");
        settype($group_id, "integer");
        settype($publish, "integer");
        settype($pin_order, "integer");
        settype($id, 'integer');
        $nodesRaw = $newObj->{"nodes"};
        if (!empty($nodesRaw)){
            $this->updatePipelinePerms($nodesRaw, $group_id, $perms, $ownerID);
        }
        if ($id > 0){
            $sql = "UPDATE biocorepipe_save set name = '$name', edges = '$edges', summary = '$summary', mainG = '$mainG', nodes ='$nodes', date_modified = now(), group_id = '$group_id', perms = '$perms', pin = '$pin', publish = '$publish', script_pipe_header = '$script_pipe_header', script_pipe_footer = '$script_pipe_footer', script_mode_header = '$script_mode_header', script_mode_footer = '$script_mode_footer', pipeline_group_id='$pipeline_group_id', process_list='$process_list', pipeline_list='$pipeline_list', publish_web_dir='$publish_web_dir', pin_order = '$pin_order', last_modified_user = '$ownerID' where id = '$id'";
        }else{
            $sql = "INSERT INTO biocorepipe_save(owner_id, summary, edges, mainG, nodes, name, pipeline_gid, rev_comment, rev_id, date_created, date_modified, last_modified_user, group_id, perms, pin, pin_order, publish, script_pipe_header, script_pipe_footer, script_mode_header, script_mode_footer,pipeline_group_id,process_list,pipeline_list, pipeline_uuid, pipeline_rev_uuid, publish_web_dir) VALUES ('$ownerID', '$summary', '$edges', '$mainG', '$nodes', '$name', '$pipeline_gid', '$rev_comment', '$rev_id', now(), now(), '$ownerID', '$group_id', '$perms', '$pin', '$pin_order', $publish, '$script_pipe_header', '$script_pipe_footer', '$script_mode_header', '$script_mode_footer', '$pipeline_group_id', '$process_list', '$pipeline_list', '$pipeline_uuid', '$pipeline_rev_uuid', '$publish_web_dir')";
        }
        return self::insTable($sql);

    }
    public function getSavedPipelines($ownerID) {
        if ($ownerID == ""){
            $ownerID ="''";
        } else {
            $userRoleCheck = $this->getUserRole($ownerID);
            if (isset(json_decode($userRoleCheck)[0])){
                $userRole = json_decode($userRoleCheck)[0]->{'role'};
                if ($userRole == "admin"){
                    $sql = "select DISTINCT pip.id, pip.rev_id, pip.name, pip.summary, pip.date_modified, u.username, pip.script_pipe_header, pip.script_pipe_footer, pip.script_mode_header, pip.script_mode_footer, pip.pipeline_group_id
                                  FROM biocorepipe_save pip
                                  INNER JOIN users u ON pip.deleted=0 AND pip.owner_id = u.id";
                    return self::queryTable($sql);
                }
            }
        }
        $where = " where pip.deleted=0 AND pip.owner_id = '$ownerID' OR pip.perms = 63 OR (ug.u_id ='$ownerID' and pip.perms = 15)";
        $sql = "select DISTINCT pip.id, pip.rev_id, pip.name, pip.summary, pip.date_modified, u.username, pip.script_pipe_header, pip.script_pipe_footer, pip.script_mode_header, pip.script_mode_footer, pip.pipeline_group_id
                            FROM biocorepipe_save pip
                            INNER JOIN users u ON pip.owner_id = u.id
                            LEFT JOIN user_group ug ON pip.group_id=ug.g_id
                            $where";
        return self::queryTable($sql);
    }
    public function loadPipeline($id,$ownerID) {
        if ($ownerID != ""){
            $userRoleCheck = $this->getUserRole($ownerID);
            if (isset(json_decode($userRoleCheck)[0])){
                $userRole = json_decode($userRoleCheck)[0]->{'role'};
                if ($userRole == "admin"){
                    $sql = "select pip.*, u.username, pg.group_name as pipeline_group_name, IF(pip.owner_id='$ownerID',1,0) as own
                                  FROM biocorepipe_save pip
                                  INNER JOIN users u ON pip.owner_id = u.id
                                  INNER JOIN pipeline_group pg ON pip.pipeline_group_id = pg.id
                                  where pip.deleted=0 AND pip.id = '$id'";
                    return self::queryTable($sql);
                }
            }
        }
        $sql = "select pip.*, u.username, pg.group_name as pipeline_group_name, IF(pip.owner_id='$ownerID',1,0) as own
                            FROM biocorepipe_save pip
                            INNER JOIN users u ON pip.owner_id = u.id
                            INNER JOIN pipeline_group pg ON pip.pipeline_group_id = pg.id
                            LEFT JOIN user_group ug ON pip.group_id=ug.g_id
                            where pip.deleted=0 AND pip.id = '$id' AND (pip.owner_id = '$ownerID' OR pip.perms = 63 OR (ug.u_id ='$ownerID' and pip.perms = 15))";
        return self::queryTable($sql);
    }
    public function removePipelineById($id) {
        $sql = "UPDATE biocorepipe_save SET deleted = 1, date_modified = now() WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function savePipelineDetails($id, $summary,$group_id, $perms, $pin, $pin_order, $publish, $pipeline_group_id, $ownerID) {
        $sql = "UPDATE biocorepipe_save SET summary='$summary', group_id='$group_id', publish='$publish', perms='$perms', pin='$pin', pin_order='$pin_order', last_modified_user = '$ownerID', pipeline_group_id='$pipeline_group_id'  WHERE id = '$id'";
        return self::runSQL($sql);
    }
    public function exportPipeline($id, $ownerID, $type, $layer) {
        $layer += 1;
        $data = $this->loadPipeline($id,$ownerID);
        $new_obj = json_decode($data,true);
        $new_obj[0]["layer"] = $layer;
        $final_obj = [];
        if ($type == "main"){
            $final_obj["main_pipeline_{$id}"]=$new_obj[0];
        } else {
            $final_obj["pipeline_module_{$id}"]=$new_obj[0];
        }
        if (!empty($new_obj[0]["nodes"])){
            $nodes = json_decode($new_obj[0]["nodes"]);
            foreach ($nodes as $item):
            if ($item[2] !== "inPro" && $item[2] !== "outPro"){
                //pipeline modules
                if (preg_match("/p(.*)/", $item[2], $matches)){
                    $pipeModId = $matches[1];
                    if (!empty($pipeModId)){
                        $pipeModule = [];
                        settype($pipeModId, "integer");
                        $pipeModule = $this->exportPipeline($pipeModId, $ownerID, "pipeModule",$layer);
                        $pipeModuleDec = json_decode($pipeModule,true);
                        $final_obj = array_merge($pipeModuleDec, $final_obj);
                    }
                    //processes
                } else {
                    $process_id = $item[2];
                    $pro_para_in = $this->getInputsPP($process_id);
                    $pro_para_out = $this->getOutputsPP($process_id);
                    $process_data = $this->getProcessDataById($process_id, $ownerID);
                    $final_obj["pro_para_inputs_$process_id"]=$pro_para_in;
                    $final_obj["pro_para_outputs_$process_id"]=$pro_para_out;
                    $final_obj["process_{$process_id}"]=$process_data;
                }
            }
            endforeach;
        }
        return json_encode($final_obj);
    }

}
