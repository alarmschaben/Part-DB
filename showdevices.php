<?PHP
/*
    part-db version 0.1
    Copyright (C) 2005 Christoph Lechner
    http://www.cl-projects.de/

    part-db version 0.2+
    Copyright (C) 2009 K. Jacobs and others (see authors.php)
    http://code.google.com/p/part-db/

    This program is free software; you can redistribute it and/or
    modify it under the terms of the GNU General Public License
    as published by the Free Software Foundation; either version 2
    of the License, or (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA

    $Id: showdevices.php 511 2012-08-05 weinbauer73@gmail.com $

    Edits:

    20120828 weinbauer73@gmail.com
    - replacing $currency by money_format()
*/

require_once ('lib.php');

// set action to default, if not exists
$action        = isset( $_REQUEST['action'])   ? $_REQUEST['action']   : 'default';
if ( isset( $_REQUEST["devicetableform_update"])) { $action = 'devicetableform_update';}
if ( isset( $_REQUEST["devicetableform_delete"])) { $action = 'devicetableform_delete';}

$sup_id            = isset( $_REQUEST['sup_id'])   ? $_REQUEST['sup_id']   : '';
$deviceid          = isset( $_REQUEST['deviceid']) ? $_REQUEST['deviceid'] : '';
$showsearchedparts = 0;
$notallinstock     = 0;
$bookstate         = 0;
$bookerrorstring   = "";
$refreshnav        = 0;
$nrows             = 6;


if ( strcmp ($action, "assignbytext") == 0 )
{
    $query = "SELECT id FROM parts WHERE name=". smart_escape($_REQUEST["newpartname"]);
    debug_print ($query);
    $result = mysql_query ($query);
    $nParts = mysql_num_rows($result);
    if( $nParts == 1)
    {
        //Check if part is allready assigned
        $partid = mysql_fetch_row ($result);
        $query = "SELECT * FROM part_device WHERE id_part=". smart_escape($partid[0]) ." AND id_device=".smart_escape( $deviceid);
        debug_print ($query);
        $result = mysql_query ($query);
        $nDevices = mysql_num_rows($result);
        if( $nDevices == 0)
        {
        //now add a part to the device
        $query = "INSERT INTO part_device (id_part,id_device,quantity) VALUES (". smart_escape($partid[0]) .",". smart_escape( $deviceid) .",1);";
        debug_print ($query);
        mysql_query ($query);
        }
        else
        {
        //Increment the part quantity
        $query = "UPDATE part_device SET quantity=quantity+1 WHERE id_part=" . smart_escape($partid[0]) . " AND id_device=".smart_escape( $deviceid);
        debug_print($query);
        mysql_query($query);
        }
    }
    else
    {
        $showsearchedparts = 1;
    }
}


if ( strcmp( $action, "assignbyselected") == 0 )
{
    $rowcount = $_REQUEST["selections"];
    while($rowcount)
    {
        if($_REQUEST["selectedid".$rowcount] && $_REQUEST["selectedquantity".$rowcount])
        {
        $query = "INSERT INTO part_device (id_part,id_device,quantity,mountname) VALUES (". smart_escape($_REQUEST["selectedid".$rowcount]) .",". smart_escape( $deviceid) .",".smart_escape($_REQUEST["selectedquantity".$rowcount]).",".smart_escape($_REQUEST["mounttext".$rowcount]).");";
        debug_print ($query);
        mysql_query ($query);
        }
        $rowcount--;
    }
}


if ( strcmp( $action, "bookparts") == 0 )
{
    //First check if enough parts are in stock
    $query = "SELECT parts.instock,part_device.quantity,parts.name FROM parts JOIN part_device ON part_device.id_part = parts.id WHERE part_device.id_device = ". smart_escape( $deviceid);
    debug_print ($query);
    $result = mysql_query ($query);
    debug_print ($result);

    $enoughinstock = 0;
    $bookstate = 2; //no parts in device
    if(mysql_num_rows($result)>0) $enoughinstock = 1;

    while ( $d = mysql_fetch_row ($result) )
    {
        $needed = $d[1]*$_REQUEST["bookmultiplikator"];
        if($d[0] < $needed)
        {
            $enoughinstock = 0;
            $bookstate = 3; //not enough parts in stock
            $bookerrorstring = $bookerrorstring.$d[2]." Benötigt: ".$needed." Im Lager: ".$d[0]."<br>";
        }
    }
    if($enoughinstock)
    {
        $query = "UPDATE parts JOIN part_device ON part_device.id_part = parts.id SET parts.instock = parts.instock - (part_device.quantity*".$_REQUEST["bookmultiplikator"].") WHERE part_device.id_device = ". smart_escape( $deviceid);
        debug_print ($query);
        $result = mysql_query ($query);
        $bookstate = (($result)?1:4); //success or query error
        debug_print ($result);
    }
}


if ( strcmp( $action, "devicetableform_update") == 0 )
{
    $n = $_REQUEST["nrofparts"];
    while($n)
    {
        //Check if the mountname is refreshed and save it if needed
        if(strcmp($_REQUEST["newmountname".$n],$_REQUEST["oldmountname".$n]) != 0)
        {
            $query = "UPDATE part_device SET mountname=".smart_escape($_REQUEST["newmountname".$n])." WHERE id_part=" . smart_escape($_REQUEST["partid".$n]) . " AND id_device=".smart_escape( $deviceid);
            debug_print($query);
            mysql_query($query);
        }
        //Check if quantity changed
        if(strcmp($_REQUEST["quant".$n],$_REQUEST["oldquant".$n]) != 0)
        {
            $query = "UPDATE part_device SET quantity=".smart_escape($_REQUEST["quant".$n])." WHERE id_part=" . smart_escape($_REQUEST["partid".$n]) . " AND id_device=".smart_escape( $deviceid);
            debug_print($query);
            mysql_query($query);
        }
        $n --;
    }
}


if ( strcmp( $action, "devicetableform_delete") == 0 )
{
    $n = $_REQUEST["nrofparts"];
    while($n)
    {
        if(isset($_REQUEST["selected".$n]))
        {
            //Remove selected parts
            $query = "DELETE FROM part_device ".
            "WHERE id_part=" . smart_escape($_REQUEST["partid".$n]) . " AND id_device=".smart_escape( $deviceid);
            debug_print($query);
            mysql_query($query);
        }
        $n --;
    }
}


if ( strcmp( $action, "renamedevice") == 0 )
{
    $query = "UPDATE devices SET name=".smart_escape($_REQUEST["newdevname"])." WHERE id=". smart_escape( $deviceid);
    mysql_query($query);
    $refreshnav = 1;
}


if ( strcmp( $action, "copydevice") == 0 )
{
    //Create a new device and get the ID
    $query = "INSERT INTO devices (name) VALUES (". smart_escape($_REQUEST["newcopydevname"]) .");";
    $r = mysql_query ($query);
    $newid = mysql_insert_id();

    //copy parent id
    $query = "SELECT parentnode FROM devices WHERE id = ". smart_escape( $deviceid);
    $result = mysql_query( $query);
    if ( $data = mysql_fetch_assoc( $result))
    {
        $query = "UPDATE devices SET parentnode=". $data['parentnode']." WHERE id=". smart_escape( $newid);
        mysql_query( $query) or die( mysql_error());
    }

    //Get the parts
    $query = "SELECT part_device.id_part,part_device.quantity,part_device.mountname FROM part_device WHERE id_device = ".smart_escape( $deviceid);
    $r = mysql_query ($query);

    //Insert the parts
    while ( $d = mysql_fetch_row ($r) )
    {
        $query = "INSERT INTO part_device (id_part,quantity,mountname,id_device) VALUES (".smart_escape($d[0]).",".smart_escape($d[1]).",".smart_escape($d[2]).",".smart_escape($newid).");";
        mysql_query ($query);
    }
    $refreshnav = 1;
}


if ( strcmp( $action, "import") == 0 )
{
    if (isset($_REQUEST["import_data"])) {
        $lines = preg_split("/\r\n/", $_REQUEST["import_data"]);
        foreach ($lines as $key => $value)
        {
            $rows = $lines = preg_split("/;/", $value);
            $rowvalid = 1;
            $addquery = "INSERT INTO part_device (id_part,quantity,mountname,id_device) VALUES (";
            foreach ($rows as $keyrow => $rowvalue)
            {
                if($keyrow == 0)    //ID
                {
                    if(!is_numeric($rowvalue))
                    {
                        $rowvalid = 0;
                    }
                    $addquery = $addquery.smart_escape($rowvalue).",";
                }
                else if($keyrow == 1)   //Quantity
                {
                    if(!is_numeric($rowvalue))
                    {
                        $rowvalid = 0;
                    }
                    $addquery = $addquery.smart_escape($rowvalue).",";
                }
                else if($keyrow == 2)   //mounting text
                {
                    $addquery = $addquery.smart_escape($rowvalue).",";
                }
            }
            $addquery = $addquery.smart_escape( $deviceid).");";
            if($rowvalid == 1)
            {
                debug_print ($addquery);
                mysql_query ($addquery);
            }
        }
    }
}

$html = new HTML;
$html -> set_html_meta ( array('title'=>'Deviceinfo Teil','menu'=>true,'validate'=>true,'popup'=>true) );
$html -> print_html_header();

if($showsearchedparts == 1)
{
    $query = "SELECT parts.name,parts.comment,parts.id,footprints.name AS 'footprint',parts.instock,parts.description FROM parts LEFT JOIN footprints ON (footprints.id = parts.id_footprint)  WHERE parts.name LIKE '%".mysql_real_escape_string($_REQUEST['newpartname'])."%' AND parts.id NOT IN(SELECT part_device.id_part FROM part_device WHERE part_device.id_device=". smart_escape( $deviceid) .")";
    $result = mysql_query ($query);
    $nParts = mysql_num_rows($result);
    $rowcount = 0;
    $showsearchedparts = array();
        while ( $data = mysql_fetch_assoc ($result) )
    {
        $rowcount++;
        $showsearchedparts[] = array(
            'rowcount'  =>  $rowcount,
            'row_odd'   =>  ((is_odd($rowcount))?'trlist_odd':'trlist_even'),
            'table_image'   =>  print_table_image($data['id'], $data['name'], $data['footprint_name']),
            'name'      =>  smart_unescape($data['name']),
            'comment'   =>  smart_unescape($data['comment']),
            'id'        =>  smart_unescape($data['id']),
            'footprint' =>  smart_unescape($data['footprint']),
            'instock'   =>  smart_unescape($data['instock']),
            'description'   =>  smart_unescape($data['description'])
        );
        }
}
if ( picture_exists( $_REQUEST["pid"]))
{
    $picture=array();
    while ($data = mysql_fetch_assoc( pictures_select( $_REQUEST["pid"]) )) $picture[] = array('id'=>$data['id']);
}

$rowcount = 0;
$query= "SELECT parts.id,parts.name,parts.description,parts.comment,parts.obsolete,footprints.name AS 'footprint',footprints.filename AS 'footprint_filename',part_device.quantity,".
    " parts.instock,storeloc.name AS 'location',suppliers.name AS 'supplier',preise.price,part_device.mountname".
    " FROM parts JOIN (part_device) ON (parts.id = part_device.id_part)".
    " LEFT JOIN preise ON (preise.part_id = parts.id)".
    " LEFT JOIN footprints ON (footprints.id = parts.id_footprint)".
    " LEFT JOIN storeloc ON (storeloc.id = parts.id_storeloc)".
    " LEFT JOIN suppliers ON (suppliers.id = parts.id_supplier)".
    " WHERE id_device = ". smart_unescape( $deviceid )." ORDER BY parts.id_category, parts.name ASC;";

$sumprice = 0;
$obsolete = false;

$table1 = array();
$result = mysql_query ($query);
if ( mysql_num_rows($result) >0 )
{
    while ( $data = mysql_fetch_assoc( $result))
    {
        $rowcount++;
        if ( $data['obsolete']) $obsolete = true;

        $table1[] = array(
            'rowcount'      =>  $rowcount,
            'row_odd'       =>  ((is_odd($rowcount))?'trlist_odd':'trlist_even'),
            'table_image'       =>  print_table_image($data['id'], $data['name'], $data['footprint_name']),
            'obsolete'      =>  $data['obsolete'],
            'comment'       =>  htmlspecialchars( smart_unescape($data['comment'])),
            'id'            =>  smart_unescape($data['id']),
            'name'          =>  smart_unescape($data['name']),
            'mountname'     =>  smart_unescape($data['mountname']),
            'footprint'     =>  smart_unescape($data['footprint']),
            'instock'       =>  smart_unescape($data['instock']),
            'quantity'      =>  smart_unescape($data['quantity']),
            'quantitiy_lt_instock'  =>  (($data['quantity'] <= $data['instock'])?true:false),
            'location'      =>  smart_unescape($data['location']),
            'supplier'      =>  smart_unescape($data['supplier']),
            'price'         =>  money_format($currency_format,smart_unescape($data['price'])),
            'price_x_quantity'  =>  money_format($currency_format,smart_unescape($data['price']*$data['quantity']))
        );
        $sumprice += $data['price'] * $data['quantity'];
    }
}

if ( strcmp( $action, "createbom") == 0 )
{
    $query= "SELECT parts.supplierpartnr,part_device.quantity,storeloc.name,suppliers.name as 'supplier',parts.name,parts.instock,preise.price".
        " FROM parts ".
        " JOIN (part_device) ON (parts.id = part_device.id_part)".
        " LEFT JOIN preise ON (preise.part_id = parts.id)".
        " LEFT JOIN footprints ON (footprints.id = parts.id_footprint)".
        " LEFT JOIN storeloc ON (storeloc.id = parts.id_storeloc)".
        " LEFT JOIN suppliers ON (suppliers.id = parts.id_supplier)".
        " WHERE id_device = ". smart_escape( $deviceid).
        (( $_REQUEST["sup_id"] != 0) ?" AND parts.id_supplier = ". $sup_id:"").
        " ORDER BY parts.id_category,parts.name ASC;";
    $result = mysql_query ($query);
    $nrows = mysql_num_rows($result)+6;

    $orderrows = array();
    $order = 1;
    while ( $row = mysql_fetch_assoc ($result) )
    {
        if(isset($_REQUEST["onlyneeded"]))
        {
            $quant = smart_unescape($d['quantity']) * $_REQUEST["multiplikator"];
             //Check if instock is greater
            if( $quant > $row['instock'] )
            {
                $quant = $quant - $row['instock'];
                $order = 1;
            }
            else
            {
                $order = 0;
            }
        }

        if ( $order )
        {
            $orderrows[]['row'] = GenerateBOMResult($_REQUEST["format"],$_REQUEST["spacer"],$row['name'],$row['supplierpartnr'],$row['supplier'],$quant,$row['instock'],money_format($currency_format,$row['price']));
        }
    }
}

$array = array(
    'deviceid'      =>  $deviceid,
    'showsearchedparts' =>  $showsearchedparts,
    'picture'       =>  $picture,
    'lookup_device_name'    =>  lookup_device_name( $deviceid ),
    'refreshnav'        =>  $refreshnav,
    'table'         =>  $table1,
    'sum_rowodd'        =>  ((is_odd($rowcount))?'trlist_odd':'trlist_even'),
    'sum_price'     =>  money_format($currency_format,$sumprice),
    'obsolete'      =>  $obsolete,
    'nrofparts'     =>  $rowcount,
    'sup_id'        =>  isset($_REQUEST["sup_id"]),
    'sup_build_list'    =>  suppliers_build_list( $sup_id ),
    'printsformats'     =>  PrintsFormats("format"),
    'spacer'        =>  ((strcmp( $action, "createbom" ))?";":$_REQUEST["spacer"]),
    'multiplikator'     =>  ((strcmp( $action, "createbom" ))?1:$_REQUEST["multiplikator"]),
    'onlyneeded'        =>  (strcmp( $action, "createbom" ) || isset($_REQUEST["onlyneeded"])),
    'nrows'         =>  $nrows,
    'GenerateBOMHeadline'   =>  GenerateBOMHeadline($_REQUEST["format"],$_REQUEST["spacer"]),
    'orders'        =>  $orderrows,
    'bookmultiplikator' =>  ((strcmp( $action, "bookparts" ))?1:$_REQUEST["bookmultiplikator"]),
    'notallinstock'     =>  $notallinstock,
    'bookstate'     =>  $bookstate,
    'bookerrorstring'   =>  $bookerrorstring,
);

$html -> parse_html_template( 'showdevices', $array );

$html -> print_html_footer();
?>