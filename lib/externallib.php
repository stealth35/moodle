<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Support for external API
 *
 * @package    moodlecore
 * @subpackage webservice
 * @copyright  2009 Moodle Pty Ltd (http://moodle.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Returns detailed function information
 * @param string|object $function name of external function or record from external_function
 * @param int $strictness IGNORE_MISSING means compatible mode, false returned if record not found, debug message if more found;
 *                        MUST_EXIST means throw exception if no record or multiple records found
 * @return object description or false if not found or exception thrown
 */
function external_function_info($function, $strictness=MUST_EXIST) {
    global $DB, $CFG;

    if (!is_object($function)) {
        if (!$function = $DB->get_record('external_functions', array('name'=>$function), '*', $strictness)) {
            return false;
        }
    }

    //first find and include the ext implementation class
    $function->classpath = empty($function->classpath) ? get_component_directory($function->component).'/externallib.php' : $CFG->dirroot.'/'.$function->classpath;
    if (!file_exists($function->classpath)) {
        throw new coding_exception('Can not find file with external function implementation');
    }
    require_once($function->classpath);

    $function->parameters_method = $function->methodname.'_parameters';
    $function->returns_method    = $function->methodname.'_returns';

    // make sure the implementaion class is ok
    if (!method_exists($function->classname, $function->methodname)) {
        throw new coding_exception('Missing implementation method');
    }
    if (!method_exists($function->classname, $function->parameters_method)) {
        throw new coding_exception('Missing parameters description');
    }
    if (!method_exists($function->classname, $function->returns_method)) {
        throw new coding_exception('Missing returned values description');
    }

    // fetch the parameters description
    $function->parameters_desc = call_user_func(array($function->classname, $function->parameters_method));
    if (!($function->parameters_desc instanceof external_function_parameters)) {
        throw new coding_exception('Invalid parameters description');
    }

    // fetch the return values description
    $function->returns_desc = call_user_func(array($function->classname, $function->returns_method));
    // null means void result or result is ignored
    if (!is_null($function->returns_desc) and !($function->returns_desc instanceof external_description)) {
        throw new coding_exception('Invalid return description');
    }

    //now get the function description
    //TODO: use localised lang pack descriptions, it would be nice to have
    //      easy to understand descriptiosn in admin UI,
    //      on the other hand this is still a bit in a flux and we need to find some new naming
    //      conventions for these descriptions in lang packs
    $function->description = null;
    $servicesfile = get_component_directory($function->component).'/db/services.php';
    if (file_exists($servicesfile)) {
        $functions = null;
        include($servicesfile);
        if (isset($functions[$function->name]['description'])) {
            $function->description = $functions[$function->name]['description'];
        }
    }

    return $function;
}

/**
 * Exception indicating user is not allowed to use external function in
 * the current context.
 */
class restricted_context_exception extends moodle_exception {
    /**
     * Constructor
     */
    function __construct() {
        parent::__construct('restrictedcontextexception', 'error');
    }
}

/**
 * Base class for external api methods.
 */
class external_api {
    private static $contextrestriction;

    /**
     * Set context restriction for all folowing subsequent function calls.
     * @param stdClass $contex
     * @return void
     */
    public static function set_context_restriction($context) {
        self::$contextrestriction = $context;
    }

    /**
     * This method has to be called before every operation
     * that takes a longer time to finish!
     *
     * @param int $seconds max expected time the next operation needs
     * @return void
     */
    public static function set_timeout($seconds=360) {
        $seconds = ($seconds < 300) ? 300 : $seconds;
        set_time_limit($seconds);
    }

    /**
     * Validates submitted function parameters, if anything is incorrect
     * invalid_parameter_exception is thrown.
     * This is a simple recursive method which is intended to be called from
     * each implementation method of external API.
     * @param external_description $description description of parameters
     * @param mixed $params the actual parameters
     * @return mixed params with added defaults for optional items, invalid_parameters_exception thrown if any problem found
     */
    public static function validate_parameters(external_description $description, $params) {
        if ($description instanceof external_value) {
            if (is_array($params) or is_object($params)) {
                throw new invalid_parameter_exception('Scalar type expected, array or object received.');
            }

            if ($description->type == PARAM_BOOL) {
                // special case for PARAM_BOOL - we want true/false instead of the usual 1/0 - we can not be too strict here ;-)
                if (is_bool($params) or $params === 0 or $params === 1 or $params === '0' or $params === '1') {
                    return (bool)$params;
                }
            }
            return validate_param($params, $description->type, $description->allownull, 'Invalid external api parameter');

        } else if ($description instanceof external_single_structure) {
            if (!is_array($params)) {
                throw new invalid_parameter_exception('Only arrays accepted.');
            }
            $result = array();
            foreach ($description->keys as $key=>$subdesc) {
                if (!array_key_exists($key, $params)) {
                    if ($subdesc->required) {
                        throw new invalid_parameter_exception('Missing required key in single structure.');
                    }
                    if ($subdesc instanceof external_value) {
                        $result[$key] = self::validate_parameters($subdesc, $subdesc->default);
                    }
                } else {
                    $result[$key] = self::validate_parameters($subdesc, $params[$key]);
                }
                unset($params[$key]);
            }
            if (!empty($params)) {
                throw new invalid_parameter_exception('Unexpected keys detected in parameter array.');
            }
            return $result;

        } else if ($description instanceof external_multiple_structure) {
            if (!is_array($params)) {
                throw new invalid_parameter_exception('Only arrays accepted.');
            }
            $result = array();
            foreach ($params as $param) {
                $result[] = self::validate_parameters($description->content, $param);
            }
            return $result;

        } else {
            throw new invalid_parameter_exception('Invalid external api description.');
        }
    }

    /**
     * Makes sure user may execute functions in this context.
     * @param object $context
     * @return void
     */
    protected static function validate_context($context) {
        if (empty($context)) {
            throw new invalid_parameter_exception('Context does not exist');
        }
        if (empty(self::$contextrestriction)) {
            self::$contextrestriction = get_context_instance(CONTEXT_SYSTEM);
        }
        $rcontext = self::$contextrestriction;

        if ($rcontext->contextlevel == $context->contextlevel) {
            if ($rcontex->id != $context->id) {
                throw new restricted_context_exception();
            }
        } else if ($rcontext->contextlevel > $context->contextlevel) {
            throw new restricted_context_exception();
        } else {
            $parents = get_parent_contexts($context);
            if (!in_array($rcontext->id, $parents)) {
                throw new restricted_context_exception();
            }
        }

        if ($context->contextlevel >= CONTEXT_COURSE) {
            //TODO: temporary bloody hack, this needs to be replaced by
            //      proper enrolment and course visibility check
            //      similar to require_login() (which can not be used
            //      because it can be used only once and redirects)
            //      oh - did I say we need to rewrite enrolments in 2.0
            //      to solve this bloody mess?
            //
            //      missing: hidden courses and categories, groupmembersonly,
            //      conditional activities, etc.
            require_capability('moodle/course:view', $context);
        }
    }
}

/**
 * Common ancestor of all parameter description classes
 */
abstract class external_description {
    /** @property string $description description of element */
    public $desc;
    /** @property bool $required element value required, null not alowed */
    public $required;

    /**
     * Contructor
     * @param string $desc
     * @param bool $required
     */
    public function __construct($desc, $required) {
        $this->desc = $desc;
        $this->required = $required;
    }
}

/**
 * Scalar alue description class
 */
class external_value extends external_description {
    /** @property mixed $type value type PARAM_XX */
    public $type;
    /** @property mixed $default default value */
    public $default;
    /** @property bool $allownull allow null values */
    public $allownull;

    /**
     * Constructor
     * @param mixed $type
     * @param string $desc
     * @param bool $required
     * @param mixed $default
     * @param bool $allownull
     */
    public function __construct($type, $desc='', $required=true, $default=null, $allownull=true) {
        parent::__construct($desc, $required);
        $this->type      = $type;
        $this->default   = $default;
        $this->allownull = $allownull;
    }
}

/**
 * Associative array description class
 */
class external_single_structure extends external_description {
     /** @property array $keys description of array keys key=>external_description */
    public $keys;

    /**
     * Constructor
     * @param array $keys
     * @param string $desc
     * @param bool $required
     */
    public function __construct(array $keys, $desc='', $required=true) {
        parent::__construct($desc, $required);
        $this->keys = $keys;
    }
}

/**
 * Bulk array description class.
 */
class external_multiple_structure extends external_description {
     /** @property external_description $content */
    public $content;

    /**
     * Constructor
     * @param external_description $content
     * @param string $desc
     * @param bool $required
     */
    public function __construct(external_description $content, $desc='', $required=true) {
        parent::__construct($desc, $required);
        $this->content = $content;
    }
}

/**
 * Description of top level - PHP function parameters.
 * @author skodak
 *
 */
class external_function_parameters extends external_single_structure {
}