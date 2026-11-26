<?php
// Builds the example plugins in examples/plugins/ into docs/hub/files/<Name>.phar (run with -d phar.readonly=0).
declare(strict_types=1);
$root = dirname(__DIR__);
foreach(glob("$root/examples/plugins/*", GLOB_ONLYDIR) as $dir){
	$name = basename($dir);
	$out = "$root/docs/hub/files/$name.phar";
	@unlink($out);
	$phar = new Phar($out);
	$phar->startBuffering();
	$phar->setStub("<?php __HALT_COMPILER();");
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach($it as $file){
		$phar->addFile($file->getPathname(), substr($file->getPathname(), strlen($dir) + 1));
	}
	$phar->compressFiles(Phar::GZ);
	$phar->stopBuffering();
	echo "$name.phar ", filesize($out), " bytes\n";
}
