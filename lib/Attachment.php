<?php declare(strict_types=1);
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
*/

namespace PartDB;

use Exception;
use PartDB\Base\AttachmentsContainingDBElement;
use PartDB\Base\DBElement;
use PartDB\Exceptions\ElementNotExistingException;
use PartDB\Exceptions\InvalidElementValueException;
use PartDB\Exceptions\TableNotExistingException;
use PartDB\Exceptions\UserNotAllowedException;
use PartDB\Permissions\CPartAttributePermission;
use PartDB\Permissions\PermissionManager;
use PartDB\Part;
use PartDB\Device;

/**
 * @file Attachement.php
 *  class Attachement
 *
 * @class Attachement
 *  All elements of this class are stored in the database table "attachements".
 */
class Attachment extends Base\NamedDBElement
{
    /********************************************************************************
     *
     *   Calculated Attributes
     *
     *   Calculated attributes will be NULL until they are requested for first time (to save CPU time)!
     *   After changing an element attribute, all calculated data will be NULLed again.
     *   So: the calculated data will be cached.
     *
     *********************************************************************************/

    /** @var DBElement the element of this attachement (for example a "Part" object) */
    private $element          = null;
    /** @var AttachmentType the type of this attachement */
    private $attachement_type = null;

    /********************************************************************************
     *
     *   Constructor / Destructor / reset_attributes()
     *
     *********************************************************************************/

    const TABLE_NAME = 'attachements';

    /**
     * Constructor
     *
     * @param Database  &$database          reference to the Database-object
     * @param User      &$current_user      reference to the current user which is logged in
     * @param Log       &$log               reference to the Log-object
     * @param integer   $id                 ID of the attachement we want to get
     *
     * @throws TableNotExistingException If the table is not existing in the DataBase
     * @throws \PartDB\Exceptions\DatabaseException If an error happening during Database AccessDeniedException
     * @throws ElementNotExistingException If no such element exists in DB.
     */
    protected function __construct(&$database, &$current_user, &$log, $id, $data = null)
    {
        parent::__construct($database, $current_user, $log, $id, $data);
    }

    /**
     * @copydoc DBElement::reset_attributes()
     */
    public function resetAttributes(bool $all = false)
    {
        $this->element          = null;
        $this->attachement_type = null;

        parent::resetAttributes($all);
    }

    /********************************************************************************
     *
     *   Basic Methods
     *
     ********************************************************************************
     * @throws Exception
     */

    public function setAttributes(array $new_values, $edit_message = null)
    {
        $this->current_user->tryDo(PermissionManager::PARTS_ATTACHEMENTS, CPartAttributePermission::EDIT);
        parent::setAttributes($new_values, $edit_message);
    }

    /**
     * Delete this attachement from database (and the associated file from harddisc if desired)
     *
     * @note This method overrides the same-named method from the parent class.
     *
     * @param boolean $delete_from_hdd      if true, and the associated file isn't used in other file records,
     *                                      the file will be deleted from harddisc drive too (!!)
     *
     * @throws Exception if the file exists and should be deleted, but cannot be deleted
     *                   (maybe not enought permissions)
     * @throws Exception if there was an error
     */
    public function delete(bool $delete_from_hdd = false)
    {
        $this->current_user->tryDo(PermissionManager::PARTS_ATTACHEMENTS, CPartAttributePermission::DELETE);

        $filename = $this->getFilename();
        $must_file_delete = false;

        if ($delete_from_hdd && (\strlen($filename) !== '')) {
            // we will delete the file only from HDD if there are no other "Attachement" objects with the same filename!
            $attachements = self::getAttachementsByFilename($this->database, $this->current_user, $this->log, $filename);

            if ((count($attachements) <= 1) && file_exists($filename)) {
                // check if there are enought permissions to delete the file
                if (! is_writable(\dirname($filename))) {
                    throw new Exception(sprintf(_('Die Datei "%s" kann nicht gelöscht werden, '.
                        'da im übergeordneten Ordner keine Schreibrechte vorhanden sind!'), $filename));
                }

                // all OK, file must be deleted after deleting the database record successfully
                $must_file_delete = true;
            }
        }

        try {
            $transaction_id = $this->database->beginTransaction(); // start transaction

            // Set all "id_master_picture_attachement" in the table "parts" to NULL where the master picture is this attachement
            $query = 'SELECT * from parts WHERE id_master_picture_attachement=?';
            $query_data = $this->database->query($query, array($this->getID()));

            foreach ($query_data as $row) {
                $part = Part::getInstance($this->database, $this->current_user, $this->log, (int) $row['id'], $row);
                $part->setMasterPictureAttachementID(null);
            }

            try {
                $this->getElement()->setAttributes(array()); // save element attributes to update its "last_modified"
            } catch (Exception $ex) {
                //Do nothing
            }


            // Now we can delete the database record of this attachement
            parent::delete();

            // now delete the file (if desired)
            if ($must_file_delete && !unlink($filename)) {
                throw new Exception(sprintf(_('Die Datei "%s" kann nicht von der Festplatte gelöscht '.
                    "werden! \nÜberprüfen Sie, ob die nötigen Rechte vorhanden sind."), $filename));
            }

            $this->database->commit($transaction_id); // commit transaction
        } catch (Exception $e) {
            $this->database->rollback(); // rollback transaction

            // restore the settings from BEFORE the transaction
            $this->resetAttributes();

            throw new Exception(sprintf(_("Der Dateianhang \"%s\" konnte nicht entfernt werden!\nGrund: "), $this->getName()) . $e->getMessage());
        }
    }

    /**
     * Check if this attachement is a picture (analyse the file's extension)
     *
     * @return boolean      @li true if the file extension is a picture extension
     *                      @li otherwise false
     */
    public function isPicture() : bool
    {
        $extension = pathinfo($this->getFilename(), PATHINFO_EXTENSION);

        // list all file extensions which are supported to display them by HTML code
        $picture_extensions = array('gif', 'png', 'jpg', 'jpeg', 'bmp', 'svg', 'tif');

        return in_array(strtolower($extension), $picture_extensions, true);
    }

    /********************************************************************************
     *
     *   Getters
     *
     *********************************************************************************/

    /**
     * Get the element, associated with this Attachement (for example a "Part" object)
     *
     * @return DBElement The associated Element.
     *
     */
    public function getElement() : DBElement
    {
        if (! is_object($this->element)) {
            $this->element = new $this->db_data['class_name'](
                $this->database,
                $this->current_user,
                $this->log,
                $this->db_data['element_id']
            );
        }

        return $this->element;
    }

    /**
     * Checks if the file in this attachement is existing. This works for files on the HDD, and for URLs
     * (it's not checked if the ressource behind the URL is really existing).
     *
     * @return bool True if the file is existing.
     */
    public function isFileExisting() : bool
    {
        return file_exists($this->getFilename()) || isURL($this->getFilename());
    }

    /**
     * Get the filename (absolute path from filesystem root, as a UNIX path [only slashes])
     *
     * @return string   the filename as an absolute UNIX filepath from filesystem root
     */
    public function getFilename() : string
    {
        return str_replace('%BASE%', BASE, $this->db_data['filename']);
    }

    /**
     * Get the show_in_table attribute
     *
     * @return bool  true means, this attachement will be listed in the "Attachements" column of the HTML tables
     *               false means, this attachement won't be listed in the "Attachements" column of the HTML tables
     */
    public function getShowInTable() : bool
    {
        return (bool) $this->db_data['show_in_table'];
    }

    /**
     *  Get the type of this attachement
     *
     * @return AttachmentType     the type of this attachement
     *
     * @throws Exception if there was an error
     */
    public function getType() : AttachmentType
    {
        if (! is_object($this->attachement_type)) {
            $this->attachement_type = AttachmentType::getInstance(
                $this->database,
                $this->current_user,
                $this->log,
                (int) $this->db_data['type_id']
            );
        }

        return $this->attachement_type;
    }

    /********************************************************************************
     *
     *   Setters
     *
     *********************************************************************************/

    /**
     *  Set the filename
     *
     * @note    The filename will not be checked, it's not really important that the filename is valid...
     *          For this reason we have the method Attachement::get_invalid_filename_attachements() :-)
     *
     * @param string $new_filename the new filename (absolute path from filesystem root as a UNIX path [only slashes]!!)
     *                             see also lib.functions.php::to_unix_path()
     *
     * @warning     It's really important that you pass the whole (UNIX) path from filesystem root!
     *              If the file is located in the base directory of Part-DB, the base path
     *              will be automatically replaced with a placeholder before write it in the database.
     *              This way, the filenames are still correct if the installation directory
     *              of Part-DB is moved.
     *
     * @throws Exception if there was an error
     */
    public function setFilename(string $new_filename)
    {
        $this->setAttributes(array('filename' => $new_filename));
    }

    /**
     *  Set the attachement type ID
     *
     * @param integer $new_type_id      the ID of the new attachement type
     *
     * @throws Exception if the new type ID is not valid
     * @throws Exception if there was an error
     */
    public function setTypeID(int $new_type_id)
    {
        $this->setAttributes(array('type_id' => $new_type_id));
    }

    /********************************************************************************
     *
     *   Static Methods
     *
     *********************************************************************************/

    /**
     *  Get all Attachement-objects with the specified filename
     *
     * @param Database  &$database          reference to the Database-object
     * @param User      &$current_user      reference to the current user which is logged in
     * @param Log       &$log               reference to the Log-object
     * @param string    $filename           the exact filename with the whole path from filesystem root as a UNIX path!
     *                                      (see Attachement::set_filename())
     *
     * @return array    all attachements as a one-dimensional array of "Attachement"-objects, sorted by their names
     *
     * @throws Exception if there was an error
     */
    public static function getAttachementsByFilename(Database $database, User $current_user, Log $log, string $filename) : array
    {
        $attachements = array();

        // if the path is relative, we will make it absolute, but you should always use absolute paths anyway!
        // Then we replace the path of the Part-DB installation directory (Constant "BASE") with a placeholder ("%BASE%")
        $filename_2 = str_replace(BASE, '%BASE%', trim($filename));

        $query =    'SELECT * FROM attachements ' .
            'WHERE filename=? OR filename=? ' .
            'ORDER BY name ASC';
        // we will search for both, the original filename and the filename with replaced base-path
        $query_data = $database->query($query, array($filename, $filename_2));

        foreach ($query_data as $row) {
            $attachements[] = Attachment::getInstance($database, $current_user, $log, (int) $row['id'], $row);
        }

        return $attachements;
    }

    /**
     *  Get all attachements with invalid filename (file does not exist)
     *
     * @note Empty filenames are NOT valid (a file without filename makes no sense)!
     *
     * @param Database  &$database          reference to the Database-object
     * @param User      &$current_user      reference to the current user which is logged in
     * @param Log       &$log               reference to the Log-object
     *
     * @return array    all attachements as a one-dimensional array of "Attachement"-objects, sorted by their names
     *
     * @throws Exception if there was an error
     */
    public static function getInvalidFilenameAttachements(Database $database, User $current_user, Log $log) : array
    {
        $attachements = array();

        $query =    'SELECT * FROM attachements ' .
            'ORDER BY name ASC';
        $query_data = $database->query($query);

        foreach ($query_data as $row) {
            if (! file_exists(str_replace('%BASE%', BASE, $row['filename']))) {
                $attachements[] = Attachment::getInstance($database, $current_user, $log, (int) $row['id'], $row);
            }
        }

        return $attachements;
    }

    /**
     * @copydoc DBElement::check_values_validity()
     */
    public static function checkValuesValidity(Database $database, User $current_user, Log $log, array &$values, bool $is_new, &$element = null)
    {
        // first, we set the basename as the name if the name is empty
        $values['name'] = trim($values['name']);
        if (empty($values['name'])) {
            $values['name'] = basename($values['filename']);
        }

        // then we let all parent classes to check the values
        parent::checkValuesValidity($database, $current_user, $log, $values, $is_new, $element);

        // set boolean attributes
        $values['show_in_table'] = (bool)$values['show_in_table'];

        // check "type_id"
        try {
            // type_id == 0 or NULL means "no attachement type", and this is not allowed!
            if ($values['type_id'] <= 0) {
                throw new InvalidElementValueException(_('"type_id" ist Null!'));
            }

            $attachement_type = AttachmentType::getInstance($database, $current_user, $log, $values['type_id']);
        } catch (ElementNotExistingException $e) {
            throw new InvalidElementValueException(_('"type_id" ist Null!'));
        }

        //Namespace migration for old non-Namespace parts
        if ($values['class_name'] == 'Part') {
            $values['class_name'] = Part::class;
        }

        // check "class_name"
        $supported_classes = array(Part::class,
            Device::class); // to be continued (step by step)...

        if (! in_array($values['class_name'], $supported_classes)) {
            throw new InvalidElementValueException(sprintf(_('Ungültiger Klassenname: "%s"'), $values['class_name']));
        }

        // check "element_id"
        try {
            // element_id == 0 is not allowed!
            if ($values['element_id'] == 0) {
                throw new InvalidElementValueException(_('"element_id" ist Null!'));
            }

            /** @var AttachmentsContainingDBElement $element */
            $element = $values['class_name']::getInstance($database, $current_user, $log, (int) $values['element_id']);
            try {
                $element->setAttributes(array()); // save element attributes to update its "last_modified"
            } catch (UserNotAllowedException $ex) {
                //Do nothing
            }
        } catch (Exception $e) {
            throw new InvalidElementValueException(_('Das gewählte Element existiert nicht!'));
        }

        // trim $values['filename']
        $values['filename'] = trim($values['filename']);

        // empty filenames are not allowed!
        if (empty($values['filename'])) {
            throw new InvalidElementValueException(_('Der Dateiname ist leer, das ist nicht erlaubt!'));
        }

        // check if "filename" is a valid (absolute and UNIX) filepath
        if (! isPathabsoluteAndUnix($values['filename'])) {
            throw new InvalidElementValueException(
                sprintf(_('Der Dateipfad "%s" ist kein gültiger absoluter UNIX Dateipfad!'), $values['filename'])
            );
        }

        // we replace the path of the Part-DB installation directory (Constant "BASE") with a placeholder ("%BASE%")
        $values['filename'] = str_replace(BASE, '%BASE%', $values['filename']);
    }


    /**
     * Create a new attachement
     *
     * @param Database  &$database reference to the database object
     * @param User      &$current_user reference to the user which is logged in
     * @param Log       &$log reference to the Log-object
     * @param DBElement &$element @li the element on which the file will be attached
     * @li For supported elements see Attachement::check_values_validity()
     * @param integer $type_id the ID of the attachement type (see Attachement::set_type_id())
     * @param string $filename the filename of the new attachement (see Attachement::set_filename())
     * @param string $name the name of the new attachement (see Attachement::set_name())
     * @param bool $show_in_table the "show_in_table" attribute of the new filename (see Attachement::set_show_in_table())
     *
     * @warning         You have to supply the full path from filesystem root in $filename!!
     *                  For more details see Attachement::set_filename().
     *
     * @return Attachment|Base\NamedDBElement
     *
     * @throws \InvalidArgumentException If the $element is not a valid DBElement
     * @throws Exception
     *
     * @see DBElement::add()
     */
    public static function add(
        Database $database,
        User $current_user,
        Log $log,
        DBElement $element,
        int $type_id,
        string $filename,
        string $name = '',
        bool $show_in_table = false
    ) {
        $current_user->tryDo(PermissionManager::PARTS_ATTACHEMENTS, CPartAttributePermission::CREATE);


        if (! $element instanceof DBElement) {
            throw new \InvalidArgumentException(_('$element ist kein gültiges DBElement!'));
        }

        return parent::addByArray(
            $database,
            $current_user,
            $log,
            array(  'name'              => $name,
                'class_name'        => get_class($element),
                'element_id'        => $element->getID(),
                'type_id'           => $type_id,
                'filename'          => $filename,
                'show_in_table'     => $show_in_table)
        );
    }

    /**
     * Returns the ID as an string, defined by the element class.
     * This should have a form like P000014, for a part with ID 14.
     * @return string The ID as a string;
     */
    public function getIDString(): string
    {
        return 'A' . sprintf('%09d', $this->getID());
    }
}
