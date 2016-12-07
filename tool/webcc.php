<?php

/**

@module webcc վ�㷢���Ż�����

��build_web.sh���ʹ�á�

ע�⣺

- �޸�webcc.conf.php�ᵼ��rebuild
- �����ǿ��rebuild, ����ɾ������ļ����µ�revision.txt, ���統�޸�webcc.php��
- ���������δ�ύ�����ݣ�Ҳ����µ�����ļ��С�
- ���û������� DBG_LEVEL=1 ��ʾ������Ϣ

Usage:

	����ԴĿ¼�����ɷ���Ŀ¼
	webcc {srcDir} [-o {outDir=output_web}]

	webcc�����������
	webcc -cmd {cmd} [-o {outFile}] [-minify yes]

webcc�������html�ļ���ʹ�ã�������JS�ϲ�ѹ����

	<!-- WEBCC_BEGIN - lib-app.min.js -->
		<script src="lib/common.js?__HASH__"></script>
		<script src="lib/app_fw.js?__HASH__"></script>
		<script src="app.js?__HASH__"></script>
	<!-- WEBCC_USE_THIS
	// ����//��ͷ��ע�ͺ� WEBCC_CMD ��ͷ������������־�ֱ�����
	WEBCC_CMD mergeJs -o lib-app.min.js -minify yes lib/common.js lib/app_fw.js app.js
	WEBCC_END -->

�ڷ���ʱ��WEBCC_BEGIN��WEBCC_USE_THIS�µ����ݽ����Ƴ����� WEBCC_USE_THIS�� WEBCC_END������ݱ������������汾�С�
������г������� `WEBCC_CMD {cmd} {args}` �����ݣ�������webcc����������

����webcc.conf.php��ָ��HASH����ʱ������webcc�����ִ�С�����

	$RULES = [
		'm2/index.html' => 'HASH',
	]

ע�⣺

- ���ʹ����-oѡ������������ָ���ļ�����ǰλ�ó��� `<script src="lib-app.min.js?v=125432">` ֮��Ŀ�Ƕ���ǩ��
  �����ʹ��-oѡ�������ֱ���������ǰλ�á�
- ѡ�� -minify yes ��ѹ�� js/css���ݣ����ļ����к��� .min. ���ļ�����ѹ������Ĭ�ϲ�ѹ����

@see webcc-mergeJs �ϲ���ѹ��JS
@see webcc-mergeCss �ϲ�CSS
@see webcc-mergePage �ϲ��߼�ҳ

@key webcc.conf.php webcc����

�÷��ɲο��ĵ���[WebӦ�ò���](WebӦ�ò���.html)


@key __HASH__  hash��ʶ

��ʽ��

	{file}?__HASH__

���ָ������ڵ�ǰ�ļ������·��{relDir}��һ������js�ļ��С�

	{file}?__HASH__,{relDir}

���磺

	loadScript("cordova/cordova.js?__HASH__,.."); // ��ʾ���ļ���Ե�ǰ�ļ���·��Ӧ���� ../cordova/cordova.js 
	loadScript("cordova-ios/cordova.js?__HASH__,../m"); // ��ʾ���ļ���Ե�ǰ�ļ���·��Ӧ���� ../m/cordova-ios/cordova.js

 */


/*
��������ʱ����ǰĿ¼ΪԴĿ¼�������ļ�ʱ��һ�����������ԴĿ¼��
�ƶ������淶���£�

$fi - �ɷ��ʵ�Դ�ļ������·������ "m2/index.html"
$outf - �ɷ��ʵ�Ŀ���ļ�������·����$outf = $g_opts['outDir'] . '/' . $fi,  �� "c:/myapp-online/m2/index.html"
$outf0,$f/$f0 - ԭʼ�ļ������ܲ��ܷ��ʣ���Ҫ��ǰ׺��
$outf1, $f1 - ��f0,f���мӹ������ʱ����
 */

//====== global {{{
$KNOWN_OPTS_FOR_CMD = ['o', 'usePageTemplate', 'minify'];
$KNOWN_OPTS = array_merge(['o', 'cmd'], $KNOWN_OPTS_FOR_CMD);

$DEF_OPTS_FOR_CMD = [
	"args" => [],
	"minify" => false,
	"usePageTemplate" => false, // Ŀǰ"template"��ǩ�ļ����Ի���������ʹ��script��ǩ
];

$g_opts = array_merge([
	"srcDir" => null,
	"outDir" => "output_web",
	"cmd" => null
], $DEF_OPTS_FOR_CMD);

$g_handledFiles = []; // elem: $file => 1
$g_hash = []; // elem: $file => $hash

const CFG_FILE = "webcc.conf.php";
$COPY_EXCLUDE = [];

// ���û������� DBG_LEVEL=1 ��ʾ������Ϣ
$DBG_LEVEL = (int)getenv("P_DEBUG") ?: 0;

$g_changedFiles = [];
$g_isRebuild = true;
$g_fakeFiles = [];

//}}}

// ====== external cmd {{{
class WebccCmd 
{
	// "-o out.js lib/a.js b.js" => opts={o: "out.js", args: ["lib/a.js", "b.js"]}
	protected $opts; // {args, ...}
	protected $isInternalCall = false;
	protected $relDir = ''; // ���·��

	// return: $fi: Դ�ļ����·�����ɷ��ʣ���$outf: Ŀ���ļ�ȫ·��
	protected function checkSrc($f, $fnName, &$outf = null)
	{
		$fi = $f;
		if ($this->relDir)
			$fi = $this->relDir . '/' . $f;
		$fi = formatPath($fi);
		if (! is_file($fi)) {
			die("*** $fnName fails: cannot find source file $fi\n");
		}

		if ($this->isInternalCall) {
			global $g_opts;
			$outf = formatPath($g_opts['outDir'] . "/" . $fi);
			handleOne($fi, $g_opts['outDir']);
			if (! is_file($outf))
				die("*** $fnName fails: cannot find handled file $fi: $outf\n");
		}
		else {
			$outf = $fi;
		}

		return $fi;
	}

	// relDir: ���·�����������ļ�ʱ��Ӧ���� relDir + '/' + �ļ��е�·��
	static function exec($cmd, $args, $isInternalCall, $relDir = null)
	{
		try {
			$fn = new ReflectionMethod('WebccCmd', $cmd);
			$cmdObj = new WebccCmd();

			global $g_opts;
			if (! $isInternalCall) {
				$cmdObj->opts = $g_opts;
			}
			else {
				global $KNOWN_OPTS_FOR_CMD, $DEF_OPTS_FOR_CMD;
				$cmdObj->opts = $DEF_OPTS_FOR_CMD;
				readOpts($args, $KNOWN_OPTS_FOR_CMD, $cmdObj->opts);
			}
			if ($relDir && $relDir != '.') {
				$cmdObj->relDir = $relDir;
			}
			$cmdObj->isInternalCall = $isInternalCall;

			$params = $fn->getParameters();
			if (count($cmdObj->opts['args']) < count($params)) {
				die("*** missing param for command: $cmd\n");
			}

			// ���ָ��-o, ���ض��������ָ���ļ�
			@$outf0 = $cmdObj->opts['o']; // ���·��
			$fi = null;
			$skipCall = false;
			global $g_handledFiles, $g_hash;
			if (isset($outf0)) {
				if ($cmdObj->relDir)
					$fi = formatPath($cmdObj->relDir . "/" . $outf0);
				else
					$fi = formatPath($outf0);
				if (array_key_exists($fi, $g_handledFiles))
					$skipCall = true;
				else
					ob_start();
			}
			if (!$skipCall)
				echo $fn->invokeArgs($cmdObj, $cmdObj->opts['args']);
			if (isset($outf0)) {
				$outf = $outf0;
				if ($isInternalCall) {
					$outf = $g_opts['outDir'] . "/" . $fi;
					@mkdir(dirname($outf), 0777, true);
				}
				if (! $skipCall) {
					$s = ob_get_contents();
					ob_end_clean();

					file_put_contents($outf, $s);
					$hash = fileHash($outf);
					$g_handledFiles[$fi] = 1;
					$g_hash[$fi] = $hash;
					logit("=== generate $fi\n");
				}
				else {
					$hash = @$g_hash[$fi] ?: fileHash($outf);
				}

				$outf1 = "$outf0?$hash"; // ���·��
				if ($cmd == 'mergeCss') {
					echo "<link rel=\"stylesheet\" href=\"$outf1\" />\n";
				}
				else if ($cmd == 'mergeJs') {
					echo "<script src=\"$outf1\"></script>\n";
				}
				else {
					echo "<script type=\"text/plain\" src=\"$outf1\"></script>\n";
				}
			}
		}
		catch (ReflectionException $ex) {
			die("*** unknown webcc command: $cmd\n");
		}
	}

/**
@fn webcc-mergeCss CSS�ϲ�

	webcc -cmd mergeCss {cssFile1} ... [-o {outFile}]

CSS�ϲ����Լ���url���·������������

����

	webcc -cmd mergeCss lib/a.css b.css -o out.css

ע�⣺ֻ�������·������Э������������

	url(data:...)
	url(http:...)

·������ʾ����

	// ���� url(...) �е�·��
	eg.  srcDir='lib', outDir='.'
	curDir='.' (��ǰ·�����outDir��·��)
	prefix = {curDir}/{srcDir} = ./lib = lib
	url(1.png) => url(lib/1.png)
	url(../image/1.png) => url(lib/../image/1.png) => url(image/1.png)

	eg2. srcDir='lib', outDir='m2/css'
	curDir='../..' (��ǰ·�����outDir��·��)
	prefix = {curDir}/{srcDir} = ../../lib
	url(1.png) => url(../lib/1.png)
	url(../image/1.png) => url(../../lib/../image/1.png) => url(../../image/1.png) (lib/..���ϲ�)

	TODO: �ݲ�֧��eg3���������outFile��������".."��ͷ��
	eg3. srcDir='lib', outDir='../m2/css'
	curDir='../../html' (���赱ǰʵ��dirΪ'prj/html')
	prefix = {curDir}/{srcDir} = ../../html/lib
	url(1.png) => url(../../html/lib/1.png)
	url(../image/1.png) => url(../../html/lib/../image/1.png) => url(../../html/image/1.png)

*/
	public function mergeCss($cssFile1)
	{
		$outDir = '.';
		if (isset($this->opts['o']))
			$outDir = dirname($this->opts['o']);
		foreach (func_get_args() as $f0) {
			$fi = $this->checkSrc($f0, "mergeCss", $outf);
			$srcDir = dirname($f0);
			$s = $this->getFile($outf);
			if ($outDir != $srcDir) {
				// TODO: �ݲ�֧��eg3���������outDir��������".."��ͷ��
				$prefix = preg_replace('/\w+/', '..', $outDir);
			   	if ($srcDir != '.')
					$prefix .= '/' . $srcDir;

				// urlģʽƥ�� [^'":]+  ����ð�ű�ʾ������Э��
				$s = preg_replace_callback('/\burl\s*\(\s*[\'"]?\s*([^\'": ]+?)\s*[\'"]?\s*\)/', function ($ms) use ($prefix){
					if ($prefix != '.') {
						$url = $prefix . '/' . $ms[1];
						// ��ѹ��·������ "./aa/bb/../cc" => "aa/cc"
						$url = preg_replace('`(^|/)\K\./`', '', $url); // "./xx" => "xx", "xx/./yy" => "xx/yy"
						$url = preg_replace('`\w+/\.\./`', '', $url);
					}
					else {
						$url = $ms[1];
					}
					return "url($url)";
				}, $s);
			}
			echo "/* webcc-css: $fi */\n";
			echo $s;
			echo "\n";
		}
	}

/**
@fn webcc-mergePage �߼�ҳ�ϲ�

	webcc -cmd mergePage {page1} ... [-usePageTemplate yes]

���߼�ҳ��html�ļ��������ӵ�js�ļ��������Ƕ����html��

����������

	webcc -cmd mergePage ../server/m2/page/home.html

������html����ʽ����

	<!-- WEBCC_BEGIN -->
	<!-- WEBCC_USE_THIS
	WEBCC_CMD mergePage page/home.html page/login.html page/login1.html page/me.html
	WEBCC_END -->

ע�⣺

- ʹ��mergePageʱ���Ὣ��ҳ��html/js������ҳ�棬Ҫ����ҳ��js�в��ɳ���script��ǩ����ΪǶ����ҳʱʹ����script����script����Ƕ�ף�
- mergePage���Ӧʹ��-oѡ���Ϊhtml�ļ��޷�����һ��htmlƬ�Ρ�

֧�����ַ�ʽ��(ͨ��ѡ�� "-usePageTemplate 1" ѡ��)

���磬�߼�ҳorder.html����order.js����ʽΪ��

	<div mui-initfn="initPageOrder" mui-script="order.js">
	</div>

1. ʹ��script��ǩǶ����ҳ�棨ȱʡ����

		<script type="text/html" id="tpl_order">
			<!-- order.html����, ��mui-script���Ա�ɾ������֮��ֱ��Ƕ��JS���� -->
			<div mui-initfn="initPageOrder" >
			</div>
		</script>

		<script>
		// order.js����
		</script>

2. ʹ��template��ǩǶ����ҳ�棨H5��׼��Ŀǰ�����Ի���������

		<template id="tpl_order">
		<!-- order.html ���� -->
		<div mui-initfn="initPageOrder" >
			<script>
			// order.js����
			</script>
		</div>
		</template>

*/
	public function mergePage($pageFile1)
	{
		$me = $this;
		foreach (func_get_args() as $f0) {
			$fi = $this->checkSrc($f0, "mergePage", $outf);
			$srcDir = dirname($fi);
			// html��ע�������٣��ݲ���minify
			$html = file_get_contents($outf);
			//$html = $this->getFile($outf);
			$html = preg_replace_callback('/(<div.*?)mui-script=[\'"]?([^\'"]+)[\'"]?(.*?>)/', function($ms) use ($srcDir, $me) {
				$js = $srcDir . '/' . $ms[2];
				if (! is_file($js)) {
					die("*** mergePage fails: cannot find js file $js\n");
				}
				return $ms[1] . $ms[3] . "\n<script>\n// webcc-js: {$ms[2]}\n" . $me->getFile($js) . "\n</script>\n";
			}, $html);

			$pageId = basename($f0, ".html");

			echo "<!-- webcc-page: $fi -->\n";
			if ($me->opts['usePageTemplate']) {
				echo "<template id=\"tpl_{$pageId}\">\n";
				echo $html;
				echo "</template>\n\n";
			}
			else {
				echo "<script type=\"text/html\" id=\"tpl_{$pageId}\">\n";
				// ʹ�� __script__ ����script��ǩǶ�ף���app_fw.js�д���__script__����ԭ��
				$html = preg_replace('`</?\K\s*script\s*(?=>)`', '__script__', $html);
				echo $html;
				echo "</script>\n\n";
			}
		}
	}

	protected function getFile($f)
	{
		if (!$this->opts['minify'] || stripos($f, '.min.') !== false) {
			return file_get_contents($f);
		}
		if (substr($f, -3) ==  '.js')
			return $this->jsmin($f);
		return $this->cssMin($f);
	}

	protected function cssMin($f)
	{
		// TODO: cssMin
		return file_get_contents($f);
	}

	// return: min js
	protected function jsmin($f)
	{
		$fp = fopen($f, "r");
		$jsminExe = __DIR__ . '/jsmin';
		$h = proc_open($jsminExe, [ $fp, ["pipe", "w"], STDERR ], $pipes);
		if ($h === false) {
			die("*** error: require tool `jsmin'\n");
		}
		fclose($fp);
		$ret = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$rv = proc_close($h);
		if ($rv != 0) {
			die("*** error: jsmin fails to run.\n");
		}
		return $ret;
	}

/**
@fn webcc-mergeJs JS�ϲ���ѹ��

	webcc -cmd mergeJs {jsFile1} ... [-o {outFile}]

��js�ļ��ϲ�����һ���ļ���������ѹ������ȥע�͡�ѹ���հף�
���Դ�ļ�������.min.js(��jquery.min.js)������Ϊ��ѹ����������ѹ����

����

	webcc -cmd mergeJs lib/jquery.min.js lib/app_fw.js app.js [-o lib_app.js]

��ѹ��ʱ����Ҫ�õ��ⲿjsmin���ߣ��ù�����webcc��ͬĿ¼�¡�
 */
	public function mergeJs($jsFile1)
	{
		foreach (func_get_args() as $f0) {
			$fi = $this->checkSrc($f0, "mergeJs", $outf);
			echo "// webcc-js: $fi\n";
			echo $this->getFile($outf);
			echo "\n";
		}
	}
}
// }}}

// ====== functions {{{
function logit($s, $level=1)
{
	global $DBG_LEVEL;
	if ($DBG_LEVEL >= $level)
		fwrite(STDERR, $s);
}

// ����ǰ·������PATH, �����ⲿ����ͬĿ¼�ĳ�����jsmin
function addPath()
{
	global $argv;
	$path = realpath(dirname($argv[0]));
	putenv("PATH=" . $path . PATH_SEPARATOR . getenv("PATH"));
}

// "xx\yy//zz" => "xx/yy/zz"
// "xx/zz/../yy" => "xx/yy"
// "./xx/./yy" => "xx/yy"
function formatPath($f)
{
	$f = preg_replace('/[\\\\\/]+/', '/', $f);
	$f = preg_replace('`[^/]+/\.\./`', '', $f);
	$f = preg_replace('`(^|/)\K\./`', '', $f);
	return $f;
}

function matchRule($rule, $file)
{
	return fnmatch($rule, $file, FNM_PATHNAME);
}

function getFileHash($basef, $f, $outDir, $relativeDir = null)
{
	global $g_hash;
	global $g_handledFiles;
	if ($relativeDir == null) {
		$relativeDir = dirname($basef);
	}
	else {
		$relativeDir = dirname($basef) . "/" . $relativeDir;
	}
	$fi = formatPath($relativeDir . "/$f");
	$outf = $outDir . "/" . $fi;
	if (!is_file($outf) || !array_key_exists($fi, $g_handledFiles))
		handleOne($fi, $outDir, true);
	if (!is_file($outf)) {
		global $g_fakeFiles;
		if (! in_array($fi, $g_fakeFiles))
			print("!!! warning: missing file `$fi` used by `$basef`\n");
		$hash = '000000';
	}
	else {
		@$hash = $g_hash[$fi];
	}
	if ($hash == null) {
		$hash = fileHash($outf);
		$g_hash[$fi] = $hash;
// 		echo("### hash {$fi}\n");
	}
	else {
// 		echo("### reuse hash({$fi})\n");
	}
	return $hash;
}

// <script src="main.js?__HASH__"></script>
// loadScript("cordova/cordova.js?__HASH__,m2)");  -> m2/cordova/cordova.js
// ���inputFile�ǿգ�ֱ�Ӷ�ȡ��; ���Ϊnull, ����$f��Ϊ���롣
function handleHash($f, $outDir, $inputFile = null)
{
	if ($inputFile == null)
		$inputFile = $f;
	$s = file_get_contents($inputFile);

	if (preg_match('/\.html/', $f)) {
		$relDir = dirname($f);
		$s = preg_replace_callback('/
			^.*WEBCC_BEGIN.*$ 
			(?:.|\n)*?
			(?:^.*WEBCC_USE_THIS.*$[\r\n]*
				((?:.|\n)*?)
			)?
			^.*WEBCC_END.*$[\r\n]*
		/xm', 
		function ($ms) use ($relDir) {
			$ret = $ms[1] ?: "";
			$ret = preg_replace('`\s*//.*$`m', '', $ret);
			$ret = preg_replace_callback('/\bWEBCC_CMD\s+(\w+)\s*(.*?)\s*$/m', function ($ms1) use ($relDir) {
				ob_start();
				list($cmd, $args) = [$ms1[1], preg_split('/\s+/', $ms1[2])];
				WebccCmd::exec($cmd, $args, true, $relDir);
				$s = ob_get_contents();
				ob_end_clean();
				return $s;
			}, $ret);
			return $ret;
		}, $s);
	}

	$s = preg_replace_callback('/"([^"]+)\?__HASH__(?:,([^"]+))?"/',
	function ($ms) use ($f, $outDir) {
		$relativeDir = @$ms[2];
		$hash = getFileHash($f, $ms[1], $outDir, $relativeDir);
		return '"' . $ms[1] . '?v=' . $hash . '"';
	}, $s);

	$outf = $outDir . "/" . $f;
	@mkdir(dirname($outf), 0777, true);
// 	echo("=== hash $f\n");
	file_put_contents($outf, $s);
}

function handleCopy($f, $outDir)
{
	$outf = $outDir . "/" . $f;
	@mkdir(dirname($outf), 0777, true);
//	echo("=== copy $f\n");

	// bugfix: Ŀ��ϵͳ��linux, ����ʱ��shell�ļ���Ҫ����.shΪ��չ�����Զ���ת��
	$dos2unix = (PHP_OS == "WINNT" && preg_match('/\.sh/', $f));
	if ($dos2unix) {
		$s = preg_replace('/\r/', '', file_get_contents($f));
		file_put_contents($outf, $s);
		return;
	}

	copy($f, $outf);
}

function handleFake($f, $outDir)
{
	global $g_fakeFiles;
	$g_fakeFiles[] = $f;
}

// return: false - skipped
function handleOne($f, $outDir, $force = false)
{
	global $FILES;
	global $RULES;
	global $COPY_EXCLUDE;
	global $g_handledFiles;

	// $FILES����һ�����ڵ��� �����ļ�
	if (!$force && isset($FILES)) {
		$skip = true;
		foreach ($FILES as $re) {
			if (matchRule($re, $f)) {
				$skip = false;
				break;
			}
		}
		if ($skip)
			return false;
	}

	$fi = formatPath($f);
	if (!$force && array_key_exists($fi, $g_handledFiles))
		return;
	$g_handledFiles[$fi] = 1;

	$rule = null;
	foreach ($RULES as $re => $v) {
		if (matchRule($re, $f)) {
			$rule = $v;
			break;
		}
	}
	if (isset($rule))
	{
		logit("=== rule '$re' on $fi\n");
		if (! is_array($rule)) {
			$rule = [ $rule ];
		}
		$outf = null;
		foreach ($rule as $rule1) {
			if ($rule1 === "HASH") {
				logit("=== hash $fi\n");
				handleHash($f, $outDir, $outf);
			}
			else if ($rule1 === "FAKE") {
				logit("=== fake $fi\n");
				handleFake($f, $outDir);
			}
			else {
				logit("=== run cmd for $fi\n");
				$outf = $outDir . "/" . $f;
				@mkdir(dirname($outf), 0777, true);
				putenv("TARGET={$outf}");
				// system($rule1);
				file_put_contents("tmp.sh", $rule1);
				passthru("sh tmp.sh");
			}
		}
		return;
	}
	global $g_isRebuild, $g_changedFiles;
	if (!$g_isRebuild) {
		if (array_search($fi, $g_changedFiles) === false)
			return false;
	}

	$noCopy = false;
	foreach ($COPY_EXCLUDE as $re) {
		if (matchRule($re, $fi)) {
			$noCopy = true;
			break;
		}
	}
	if ($noCopy)
		return false;
	if (! is_file($fi)) {
		print("!!! warning: missing file `$fi`.\n");
		return;
	}
	logit("=== copy $fi\n", 5);
	handleCopy($f, $outDir);
}

// ֱ�Ӹ�д������� $opts
function readOpts($args, $knownOpts, &$opts)
{
	reset($args);
	for (; ($opt = current($args)) !== false; next($args)) {
		if ($opt[0] === '-') {
			$opt = substr($opt, 1);
			if (! in_array($opt, $knownOpts)) {
				die("*** unknonw option `$opt`.\n");
			}

			$v = next($args);
			if ($v === false)
				die("*** require value for option `$opt`\n");
			if ($v == 'yes' || $v == 'true')
				$v = true;
			else if ($v == 'no' || $v == 'false')
				$v = false;
			$opts[$opt] = $v;

			continue;
		}
		$opts["args"][] = $opt;
	}
	return $opts;
}

function fileHash($f)
{
	return substr(sha1_file($f), -6);
}
//}}}

// ====== main {{{

// ==== parse args {{{
if (count($argv) < 2) {
	echo("Usage: webcc {srcDir} [-o {outDir=output_web}]\n");
	echo("       webcc [-o {outFile}] -cmd {cmd} [args]\n");
	exit(1);
}

array_shift($argv);
readOpts($argv, $KNOWN_OPTS, $g_opts);
if (isset($g_opts['cmd'])) {
	WebccCmd::exec($g_opts['cmd'], $g_opts['args'], false);
	exit;
}

if (isset($g_opts['o']))
	$g_opts["outDir"] = $g_opts['o'];

$g_opts["srcDir"] = $g_opts['args'][0];

if (is_null($g_opts["srcDir"])) 
	die("*** require param srcDir.");
if (! is_dir($g_opts["srcDir"]))
	die("*** not a folder: `{$g_opts["srcDir"]}`\n");

addPath();
// load config
$cfg = $g_opts["srcDir"] . "/" . CFG_FILE;
if (is_file($cfg)) {
	echo("=== load config `$cfg`\n");
	require($cfg);
}

$COPY_EXCLUDE[] = CFG_FILE;
//}}}

@mkdir($g_opts["outDir"], 0777, true);
$g_opts["outDir"] = realpath($g_opts["outDir"]);
$outDir = $g_opts["outDir"];
$verFile = "$outDir/revision.txt";
$oldVer = null;
if (file_exists($verFile)) {
	$oldVer = @file($verFile, FILE_IGNORE_NEW_LINES)[0];
}

chdir($g_opts["srcDir"]);
if (isset($oldVer)) {
	$g_isRebuild = false;
	// NOTE: ���޵�ǰĿ¼(srcDir)�Ķ�
	$cmd = "git diff $oldVer --name-only --diff-filter=AM --relative";
	exec($cmd, $g_changedFiles, $rv);
	if (count($g_changedFiles) == 0)
		exit;
}
else {
	echo("!!! build all files !!!\n");
}

$allFiles = null;
$cmd = "git ls-files";
exec($cmd, $allFiles, $rv);

$updateVer = false;
foreach ($allFiles as $f) {
	if (handleOne($f, $outDir) !== false)
	{
		$updateVer = true;
	}
}

if ($updateVer) {
	// update new version
	system("git log -1 --format=%H > $verFile");
}

echo("=== output to `$outDir`\n");
//}}}
// vim: set foldmethod=marker :
