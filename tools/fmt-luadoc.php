#!/usr/bin/php
<?php
## USAGE
#
# ./waf configure --luadoc ....
# ./waf
# ./gtk2_ardour/arluadoc > doc/luadoc.json
#
# php tools/fmt-luadoc.php > /tmp/luadoc.html
#

################################################################################
################################################################################

$json = file_get_contents (dirname (__FILE__).'/../doc/luadoc.json');
$doc = array ();
foreach (json_decode ($json, true) as $b) {
	if (!isset ($b['type'])) { continue; }
	$doc[] = $b;
}

if (count ($doc) == 0) {
	fwrite (STDERR, "Failed to read luadoc.json\n");
	exit (1);
}

################################################################################
## Global result variables
################################################################################

$classlist = array ();
$constlist = array ();


################################################################################
## Pre-process the data, collect functions, parse arguments, cross reference
################################################################################


################################################################################
# some internal helper functions first

$funclist = array ();
$classes = array ();
$consts = array ();

function my_die ($msg) {
	fwrite (STDERR, $msg."\n");
	exit (1);
}

##function ptr_strip ($ctype) {
#	# boost::shared_ptr<std::list<boost::shared_ptr<ARDOUR::Route>> > >
#	# -> std::list<ARDOUR::Route>
#	$ctype = preg_replace ('/boost::shared_ptr<([^>]*)[ ]*>/', '$1', $ctype);
#	return preg_replace ('/boost::shared_ptr<([^>]*)[ ]*>/', '$1', $ctype);
#}

function arg2lua ($argtype) {
	global $classes;
	global $consts;

	# LuaBridge abstracts C++ references
	$flags = preg_match ('/&$/', $argtype);
	$arg = preg_replace ('/&$/', '', $argtype);

	# filter out basic types
	$builtin = array ('float', 'double', 'bool', 'std::string', 'int', 'long', 'unsigned long', 'unsigned int', 'unsigned char', 'char', 'void', 'char*', 'unsigned char*', 'void*');
	if (in_array ($arg, $builtin)) {
		return array ($arg => $flags);
	}

	# check Class declarations first
	foreach (array_merge ($classes, $consts) as $b) {
		if ($b['decl'] == $arg) {
			return array ($b['lua'] => $flags);
		}
	}

	# strip class pointers -- TODO Check C'tor for given class
	$arg = preg_replace ('/[&*]*$/', '', $argtype);
	foreach (array_merge ($classes, $consts) as $b) {
		if ($b['decl'] == $arg) {
			return array ($b['lua'] => $flags);
		}
	}
	return array ('--MISSING (' . $argtype . ')--' => ($flags | 4));
}

function stripclass ($classname, $name) {
	$classname .= ':';
	if (strpos ($name, $classname) !== 0) {
		my_die ('invalid class prefix: ' .$classname. ' -- '. $name);
	}
	return substr ($name, strlen ($classname));
}

function datatype ($decl) {
	# TODO handle spaces in type. Works because
	# we don't yet have templated types (with_space <here >)
	return substr ($decl, 0, strpos ($decl, ' '));
}

function luafn2class ($lua) {
	return substr ($lua, 0, strrpos ($lua, ':'));
}

function checkclass ($b) {
	global $classlist;
	if (!isset ($classlist[luafn2class ($b['lua'])])) {
		my_die ('MISSING CLASS FOR '. print_r ($b['lua'], true));
	}
}

# parse functions argument list to lua-names
function decl2args ($decl) {
	$start = strrpos ($decl, '(');
	$end = strrpos ($decl, ')');
	$args = substr ($decl, $start + 1, $end - $start - 1);
	$arglist = preg_split ('/, */', $args);
	$rv = array ();
	foreach ($arglist as $a) {
		if (empty ($a)) { continue; }
		$rv[] = arg2lua ($a);
	}
	return $rv;
}

################################################################################
# step 1: build class indices

foreach ($doc as $b) {
	if (strpos ($b['type'], "[C] ") === 0) {
		$classes[] = $b;
		$classlist[$b['lua']] = $b;
		if (strpos ($b['type'], 'Pointer Class') === false) {
			$classdecl[$b['decl']] = $b;
		}
	}
}

foreach ($classes as $c) {
	if (strpos ($c['type'], 'Pointer Class') !== false) { continue; }
	if (isset ($c['parent'])) {
		if (isset ($classdecl[$c['parent']])) {
			$classlist[$c['lua']]['luaparent'][] = $classdecl[$c['parent']]['lua'];
		} else {
			my_die ('unknown parent class: ' . print_r ($c, true));
		}
	}
}

# step 2: extract constants/enum
foreach ($doc as $b) {
	switch ($b['type']) {
	case "Constant/Enum":
	case "Constant/Enum Member":
		if (strpos ($b['decl'], '::') === false) {
			# for extern c enums, use the Lua Namespace
			$b['decl'] = str_replace (':', '::', luafn2class ($b['lua']));
		}
		$ns = str_replace ('::', ':', $b['decl']);
		$constlist[$ns][] = $b;
		# arg2lua lookup
		$b['lua'] = $ns;
		$consts[] = $b;
		break;
	default:
		break;
	}
}

# step 3: process functions
foreach ($doc as $b) {
	switch ($b['type']) {
	case "Constructor":
	case "Weak/Shared Pointer Constructor":
		checkclass ($b);
		$classlist[luafn2class ($b['lua'])]['ctor'][] = array (
			'name' => luafn2class ($b['lua']),
			'args' => decl2args ($b['decl']),
		);
		break;
	case "Data Member":
		checkclass ($b);
		$classlist[luafn2class ($b['lua'])]['data'][] = array (
			'name' => $b['lua'],
			'ret'  => arg2lua (datatype ($b['decl']))
		);
		break;
	case "C Function":
		# we required C functions to be in a class namespace
	case "Ext C Function":
		checkclass ($b);
		$args = array (array ('--custom--' => 0));
		$ret = array ('...' => 0);
		$ns = luafn2class ($b['lua']);
		$cls = $classlist[$ns];
		## std::Vector std::List types
		if (preg_match ('/.*<([^>]*)[ ]*>/', $cls['decl'], $templ)) {
			// XXX -> move to C-source
			switch (stripclass($ns, $b['lua'])) {
			case 'add':
				$args = array (array ('LuaTable {'.$templ[1].'}' => 0));
				$ret = array ('void' => 0);
				break;
			case 'iter':
				$args = array ();
				$ret = array ('LuaIter' => 0);
				break;
			case 'table':
				$args = array ();
				$ret = array ('LuaTable' => 0);
				break;
			default:
				break;
			}
		} else if (strpos ($cls['type'], ' Array') !== false) {
			$templ = preg_replace ('/[&*]*$/', '', $cls['decl']);
			switch (stripclass($ns, $b['lua'])) {
			case 'array':
				$args = array ();
				$ret = array ('LuaMetaTable' => 0);
				break;
			case 'get_table':
				$args = array ();
				$ret = array ('LuaTable' => 0);
				break;
			case 'set_table':
				$args = array (array ('LuaTable {'.$templ.'}' => 0));
				$ret = array ('void' => 0);
				break;
			default:
				break;
			}
		}
		$classlist[luafn2class ($b['lua'])]['func'][] = array (
			'name' => $b['lua'],
			'args' => $args,
			'ret'  => $ret,
			'ref'  => true,
			'ext'  => true
		);
		break;
	case "Free Function":
	case "Free Function RefReturn":
		$funclist[luafn2class ($b['lua'])][] = array (
			'name' => $b['lua'],
			'args' => decl2args ($b['decl']),
			'ret'  => arg2lua ($b['ret']),
			'ref'  => (strpos ($b['type'], "RefReturn") !== false)
		);
		break;
	case "Member Function":
	case "Member Function RefReturn":
	case "Member Pointer Function":
	case "Weak/Shared Pointer Function":
	case "Weak/Shared Pointer Function RefReturn":
	case "Weak/Shared Null Check":
	case "Weak/Shared Pointer Cast":
	case "Static Member Function":
		checkclass ($b);
		$classlist[luafn2class ($b['lua'])]['func'][] = array (
			'name' => $b['lua'],
			'args' => decl2args ($b['decl']),
			'ret'  => arg2lua ($b['ret']),
			'ref'  => (strpos ($b['type'], "RefReturn") !== false)
		);
		break;
	case "Constant/Enum":
	case "Constant/Enum Member":
		# already handled -> $consts
		break;
	default:
		if (strpos ($b['type'], "[C] ") !== 0) {
			my_die ('unhandled type: ' . $b['type']);
		}
		break;
	}
}


# step 4: collect/group/sort

# step 4a: unify weak/shared Ptr classes
foreach ($classlist as $ns => $cl) {
	if (strpos ($cl['type'], ' Array') !== false) {
		$classlist[$ns]['arr'] = true;
		continue;
	}
	foreach ($classes as $c) {
		if ($c['lua'] == $ns) {
			if (strpos ($c['type'], 'Pointer Class') !== false) {
				$classlist[$ns]['ptr'] = true;
				$classlist[$ns]['decl'] = 'boost::shared_ptr< '.$c['decl']. ' >, boost::weak_ptr< '.$c['decl']. ' >';
				break;
			}
		}
	}
}

# step4b: sanity check
foreach ($classlist as $ns => $cl) {
	if (isset ($classes[$ns]['parent']) && !isset ($classlist[$ns]['luaparent'])) {
		my_die ('missing parent class: ' . print_r ($cl, true));
	}
}

# step 4c: merge free functions into classlist
foreach ($funclist as $ns => $fl) {
	if (isset ($classlist[$ns])) {
		my_die ('Free Funcion in existing namespace: '.$ns.' '. print_r ($ns, true));
	}
	$classlist[$ns]['func'] = $fl;
	$classlist[$ns]['free'] = true;
}

# step 4d: order to chaos
# no array_multisort() here, sub-types are sorted after merging parents
ksort ($classlist);


################################################################################
################################################################################
################################################################################


#### -- split here --  ####

# from here on, only $classlist and $constlist arrays are relevant.

# TODO: read function documentation from doxygen
# and/or reference source-code lines e.g from CSV list:
#   ctags -o /tmp/tags.csv --fields=+afiKkmnsSzt libs/ardour/ardour/session.h


################################################################################
# OUTPUT
################################################################################


################################################################################
# Helper functions
define ('NL', "\n");

function ctorname ($name) {
	return htmlentities (str_replace (':', '.', $name));
}

function shortname ($name) {
	return htmlentities (substr ($name, strrpos ($name, ':') + 1));
}

function varname ($a) {
	return array_keys ($a)[0];
}

function name_sort_cb ($a, $b) {
	return strcmp ($a['name'], $b['name']);
}

function traverse_parent ($ns, &$inherited) {
	global $classlist;
	$rv = '';
	if (isset ($classlist[$ns]['luaparent'])) {
		$parents = array_unique ($classlist[$ns]['luaparent']);
		asort ($parents);
		foreach ($parents as $p) {
			if (!empty ($rv)) { $rv .= ', '; }
			$rv .= typelink ($p);
			$inherited[$p] = $classlist[$p];
			traverse_parent ($p, $inherited);
		}
	}
	return $rv;
}

function typelink ($a, $short = false, $argcls = '', $linkcls = '', $suffix = '') {
	global $classlist;
	global $constlist;
	# all cross-reference links are generated here.
	# currently anchors on a single page.
	if (in_array ($a, array_keys ($classlist))) {
		return '<a class="'.$linkcls.'" href="#'.htmlentities($a).'">'.($short ? shortname($a) : htmlentities($a)).$suffix.'</a>';
	} else if (in_array ($a, array_keys ($constlist))) {
		return '<a class="'.$linkcls.'" href="#'.ctorname ($a).'">'.($short ? shortname($a) : ctorname($a)).$suffix.'</a>';
	} else {
		return '<span class="'.$argcls.'">'.htmlentities($a).$suffix.'</span>';
	}
}

function format_args ($args) {
	$rv = '<span class="functionargs"> (';
	$first = true;
	foreach ($args as $a) {
		if (!$first) { $rv .= ', '; }; $first = false;
		$flags = $a[varname ($a)];
		if ($flags & 1) {
			$rv .= typelink (varname ($a), true, 'em', '', '&amp;');
		} else {
			$rv .= typelink (varname ($a), true, 'em');
		}
	}
	$rv .= ')</span>';
	return $rv;
}

function format_class_members ($ns, $cl, &$dups) {
	$rv = '';
	if (isset ($cl['ctor'])) {
		usort ($cl['ctor'], 'name_sort_cb');
		$rv.= ' <tr><th colspan="3">Constructor</th></tr>'.NL;
		foreach ($cl['ctor'] as $f) {
			$rv.= ' <tr><td class="def">&Copf;</td><td class="decl">';
			$rv.= '<span class="functionname">'.ctorname ($f['name']).'</span>';
			$rv.= format_args ($f['args']);
			$rv.= '</td><td class="fill"></td></tr>'.NL;
		}
	}
	$nondups = array ();
	if (isset ($cl['func'])) {
		foreach ($cl['func'] as $f) {
			if (in_array (stripclass ($ns, $f['name']), $dups)) { continue; }
			$nondups[] = $f;
		}
	}
	if (count ($nondups) > 0) {
		usort ($nondups, 'name_sort_cb');
		$rv.= ' <tr><th colspan="3">Methods</th></tr>'.NL;
		foreach ($nondups as $f) {
			$dups[] = stripclass ($ns, $f['name']);
			$rv.= ' <tr><td class="def">';
			if ($f['ref'] && isset ($f['ext'])) {
				# external C functions
				$rv.= '<em>'.varname ($f['ret']).'</em>';
			} elseif ($f['ref'] && varname ($f['ret']) == 'void') {
				# functions with reference args return args
				$rv.= '<em>LuaTable</em>(...)';
			} elseif ($f['ref']) {
				$rv.= '<em>LuaTable</em>('.typelink (varname ($f['ret'], false, 'em')).', ...)';
			} else {
				$rv.= typelink (varname ($f['ret']), true, 'em');
			}
			$rv.= '</td><td class="decl">';
			$rv.= '<span class="functionname">'.stripclass ($ns, $f['name']).'</span>';
			$rv.= format_args ($f['args']);
			$rv.= '</td><td class="fill"></td></tr>'.NL;
		}
	}
	if (isset ($cl['data'])) {
		usort ($cl['data'], 'name_sort_cb');
		$rv.= ' <tr><th colspan="3">Data Members</th></tr>'.NL;
		foreach ($cl['data'] as $f) {
			$rv.= ' <tr><td class="def">'.typelink (array_keys ($f['ret'])[0], false, 'em').'</td><td class="decl">';
			$rv.= '<span class="functionname">'.stripclass ($ns, $f['name']).'</span>';
			$rv.= '</td><td class="fill"></td></tr>'.NL;
		}
	}
	return $rv;
}


################################################################################
# Start Output

?><!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">
<head>
<title>Ardour Lua Bindings</title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<style type="text/css">
div.content        { max-width:60em; margin: 1em auto; }
h1                 { margin:2em 0 0 0; padding:0em; border-bottom: 1px solid black;}
h2.cls             { margin:2em 0 0 0; padding-left:1em; border: 1px dashed #6666ee;}
h2.cls abbr        { text-decoration:none; cursor:default;}
h3.cls             { margin:1em 0 0 0;}
h2.class           { background-color: #aaee66; }
h2.enum            { background-color: #aaaaaa; }
h2.pointerclass    { background-color: #eeaa66; }
h2.array           { background-color: #66aaee; }
h2.opaque          { background-color: #6666aa; }
p.cdecl            { text-align: right; float:right; font-size:90%; margin:0; padding: 0 0 0 1em;}
ul.classindex      { columns: 2; -webkit-columns: 2; -moz-columns: 2; }
div.clear          { clear:both; }
table.classmembers { width: 100%; }
table.classmembers th      { text-align:left; border-bottom:1px solid black; padding-top:1em; }
table.classmembers td.def  { text-align:right; padding-right:.5em;  white-space: nowrap;}
table.classmembers td.decl { text-align:left; padding-left:.5em; white-space: nowrap; }
table.classmembers td.fill { width: 99%;}
table.classmembers span.em { font-style: italic;}
div.header         {text-align:center;}
div.header h1      {margin:0;}
div.header p       {margin:.25em;}
</style>
</head>
<body>
<div class="header">
<h1>Ardour Lua Bindings</h1>
<p>
<a href="#h_classes">Class Documentation</a>
&nbsp;|&nbsp;
<a href="#h_enum">Enum/Constants</a>
&nbsp;|&nbsp;
<a href="#h_index">Index</a>
</p>
</div>
<div class="content">

<h1 id="h_intro">Overview</h1>
<p>
The top-level entry point are <?=typelink('ARDOUR:Session')?> and <?=typelink('ARDOUR:Editor')?>.
Most other Classes are used indirectly starting with a Session function. e.g. Session:get_routes().
</p>
<p>
A few classes are dedicated to certain script types, e.g. Lua DSP processors have exclusive access to
<?=typelink('ARDOUR:DSP')?> and <?=typelink('ARDOUR:ChanMapping')?>. Action Hooks Scripts to
<?=typelink('LuaSignal:Set')?> etc.
</p>
<p>
Detailed documentation (parameter names, method description) is not yet available. Please stay tuned.
</p>

<?php
echo '<h1 id="h_classes">Class Documentation</h1>'.NL;

foreach ($classlist as $ns => $cl) {
	$dups = array ();
	$tbl =  format_class_members ($ns, $cl, $dups);

	# format class title
	if (empty ($tbl)) {
		echo '<h2 id="'.$ns.'" class="cls opaque"><abbr title="Opaque Object">&empty;</abbr>&nbsp;'.$ns.'</h2>'.NL;
	}
	else if (isset ($classlist[$ns]['free'])) {
		echo '<h2 id="'.$ns.'" class="cls freeclass"><abbr title="Namespace">&Nopf;</abbr>&nbsp;'.$ns.'</h2>'.NL;
	}
	else if (isset ($classlist[$ns]['arr'])) {
		echo '<h2 id="'.$ns.'" class="cls array"><abbr title="C Array">&ctdot;</abbr>&nbsp;'.$ns.'</h2>'.NL;
	}
	else if (isset ($classlist[$ns]['ptr'])) {
		echo '<h2 id="'.$ns.'" class="cls pointerclass"><abbr title="Pointer Class">&Rarr;</abbr>&nbsp;'.$ns.'</h2>'.NL;
	} else {
		echo '<h2 id="'.htmlentities ($ns).'" class="cls class"><abbr title="Class">&comp;</abbr>&nbsp;'.htmlentities ($ns).'</h2>'.NL;
	}
	if (isset ($cl['decl'])) {
		echo '<p class="cdecl"><em>C&#8225;</em>: '.htmlentities ($cl['decl']).'</p>'.NL;
	}

	# print class inheritance
	$inherited = array ();
	$isa = traverse_parent ($ns, $inherited);
	if (!empty ($isa)) {
		echo ' <p>is-a: '.$isa.'</p>'.NL;
	}
	echo '<div class="clear"></div>'.NL;

	# member documentation
	if (empty ($tbl)) {
		echo '<p>This class object is only used indirectly as return-value and function-parameter.</p>'.NL;
	} else {
		echo '<table class="classmembers">'.NL;
		echo $tbl;
		echo ' </table>'.NL;
	}

	# traverse parent classes (inherited members)
	foreach ($inherited as $pns => $pcl) {
		$tbl = format_class_members ($pns, $pcl, $dups);
		if (!empty ($tbl)) {
			echo '<h3 class="cls">Inherited from '.$pns.'</h3>'.NL;
			echo '<table class="classmembers">'.NL;
			echo $tbl;
			echo '</table>'.NL;
		}
	}
}

echo '<h1 id="h_enum">Enum/Constants</h1>'.NL;
foreach ($constlist as $ns => $cs) {
	echo '<h2 id="'.ctorname ($ns).'" class="cls enum"><abbr title="Enum">&isin;</abbr>&nbsp;'.ctorname ($ns).'</h2>'.NL;
	echo '<ul class="enum">'.NL;
	foreach ($cs as $c) {
		echo '<li class="const">'.ctorname ($c['lua']).'</li>'.NL;
	}
	echo '</ul>'.NL;
}

echo '<h1 id="h_index" >Class Index</h1>'.NL;
echo '<ul class="classindex">'.NL;
foreach ($classlist as $ns => $cl) {
	echo '<li>'.typelink($ns).'</li>'.NL;
}
echo '</ul>'.NL;

?>
</div>
</body>
</html>