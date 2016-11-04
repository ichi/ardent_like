<?php
/**
 * Ardentのvalidation周りだけ拝借
 */

namespace Ichi\ArdentLike;

use Closure;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ardent - Self-validating Eloquent model base class
 *
 */
abstract class ArdentLike extends Model {

    /**
     * The rules to be applied to the data.
     *
     * @var array
     */
    public static $rules = array();

    /**
     * The array of custom error messages.
     *
     * @var array
     */
    public static $customMessages = array();

    /**
     * The array of custom attributes.
     *
     * @var array
     */
    public static $customAttributes = array();

    /**
     * The validator object in case you need it externally (say, for a form builder).
     *
     * @see getValidator()
     * @var \Illuminate\Validation\Validator
     */
    protected $validator;

    /**
     * The message bag instance containing validation error messages
     *
     * @var \Illuminate\Support\MessageBag
     */
    public $validationErrors;

    /**
     * Makes the validation procedure throw an {@link InvalidModelException} instead of returning
     * false when validation fails.
     *
     * @var bool
     */
    public $throwOnValidation = false;

    /**
     * If set to true, the object will automatically populate model attributes from Input::all()
     *
     * @var bool
     */
    public $autoHydrateEntityFromInput = false;

    /**
     * By default, Ardent will attempt hydration only if the model object contains no attributes and
     * the $autoHydrateEntityFromInput property is set to true.
     * Setting $forceEntityHydrationFromInput to true will bypass the above check and enforce
     * hydration of model attributes.
     *
     * @var bool
     */
    public $forceEntityHydrationFromInput = false;

    /**
     * If set to true, the object will automatically remove redundant model
     * attributes (i.e. confirmation fields).
     *
     * @var bool
     */
    public $autoPurgeRedundantAttributes = false;

    /**
     * Array of closure functions which determine if a given attribute is deemed
     * redundant (and should not be persisted in the database)
     *
     * @var array
     */
    protected $purgeFilters = array();

    protected $purgeFiltersInitialized = false;

    /**
     * List of attribute names which should be hashed using the Bcrypt hashing algorithm.
     *
     * @var array
     */
    public static $passwordAttributes = array('password');

    /**
     * If set to true, the model will automatically replace all plain-text passwords
     * attributes (listed in $passwordAttributes) with hash checksums
     *
     * @var bool
     */
    public $autoHashPasswordAttributes = false;

    /**
     * If set to true will try to instantiate other components as if it was outside Laravel.
     *
     * @var bool
     */
    protected static $external = false;

    /**
     * A Validation Factory instance, to be used by standalone Ardent instances with the Translator.
     *
     * @var \Illuminate\Validation\Factory
     */
    protected static $validationFactory;

    /**
     * An instance of a Hasher object, to be used by standalone Ardent instances. Will be null if not external.
     *
     * @var \Illuminate\Contracts\Hashing\Hasher
     * @see LaravelArdent\Ardent\Ardent::configureAsExternal()
     */
    public static $hasher;



    /**
     * Create a new Ardent model instance.
     *
     * @param array $attributes
     * @return \LaravelArdent\Ardent\Ardent
     */
    public function __construct(array $attributes = array()) {
        parent::__construct($attributes);
        $this->validationErrors = new MessageBag;
    }

    /**
     * The "booting" method of the model.
     * Overrided to attach before/after method hooks into the model events.
     *
     * @see \Illuminate\Database\Eloquent\Model::boot()
     * @return void
     */
    protected static function boot() {
        parent::boot();

        $myself   = get_called_class();
        $hooks    = array('before' => 'ing', 'after' => 'ed');
        $radicals = array('sav', 'validat', 'creat', 'updat', 'delet');

        foreach ($radicals as $rad) {
            foreach ($hooks as $hook => $event) {
                $method = $hook.ucfirst($rad).'e';
                if (method_exists($myself, $method)) {
                    $eventMethod = $rad.$event;
                    self::$eventMethod(function($model) use ($method){
                        return $model->$method($model);
                    });
                }
            }
        }
    }

	public function getObservableEvents() {
		return array_merge(
			parent::getObservableEvents(),
			array('validating', 'validated')
		);
	}

	/**
	 * Register a validating model event with the dispatcher.
	 *
	 * @param Closure|string $callback
	 * @return void
	 */
	public static function validating($callback) {
		static::registerModelEvent('validating', $callback);
	}

	/**
	 * Register a validated model event with the dispatcher.
	 *
	 * @param Closure|string $callback
	 * @return void
	 */
	public static function validated($callback) {
		static::registerModelEvent('validated', $callback);
	}

    /**
     * Instatiates the validator used by the validation process, depending if the class is being used inside or
     * outside of Laravel.
     *
     * @param $data
     * @param $rules
     * @param $customMessages
     * @param $customAttributes
     * @return \Illuminate\Validation\Validator
     * @see Ardent::$externalValidator
     */
    protected static function makeValidator($data, $rules, $customMessages, $customAttributes) {
        return self::$external?
            self::$validationFactory->make($data, $rules, $customMessages, $customAttributes) :
            Validator::make($data, $rules, $customMessages, $customAttributes);
    }

    /**
     * Validate the model instance
     *
     * @param array $rules            Validation rules
     * @param array $customMessages   Custom error messages
     * @param array $customAttributes Custom attributes
     * @return bool
     * @throws InvalidModelException
     */
    public function validate(array $rules = array(), array $customMessages = array(), array $customAttributes = array()) {
        if ($this->fireModelEvent('validating') === false) {
            if ($this->throwOnValidation) {
                throw new InvalidModelException($this);
            } else {
                return false;
            }
        }

        // check for overrides, then remove any empty rules
        $rules = (empty($rules))? static::$rules : $rules;
        foreach ($rules as $field => $rls) {
            if ($rls == '') {
                unset($rules[$field]);
            }
        }

        if (empty($rules)) {
            $success = true;
        } else {
            $customMessages = (empty($customMessages))? static::$customMessages : $customMessages;
            $customAttributes = (empty($customAttributes))? static::$customAttributes : $customAttributes;

            if ($this->forceEntityHydrationFromInput || (empty($this->attributes) && $this->autoHydrateEntityFromInput)) {
                $this->fill(Input::all());
            }

            $data = $this->getAttributes(); // the data under validation

            // perform validation
            $this->validator = static::makeValidator($data, $rules, $customMessages, $customAttributes);
            $success   = $this->validator->passes();

            if ($success) {
                // if the model is valid, unset old errors
                if ($this->validationErrors === null || $this->validationErrors->count() > 0) {
                    $this->validationErrors = new MessageBag;
                }
            } else {
                // otherwise set the new ones
                $this->validationErrors = $this->validator->messages();

                // stash the input to the current session
                if (!self::$external && Input::hasSession()) {
                    Input::flash();
                }
            }
        }

        $this->fireModelEvent('validated', false);

        if (!$success && $this->throwOnValidation) {
            throw new InvalidModelException($this);
        }

        return $success;
    }

    /**
     * Save the model to the database. Is used by {@link save()} and {@link forceSave()} as a way to DRY code.
     *
     * @param array   $rules
     * @param array   $customMessages
     * @param array   $options
     * @param Closure $beforeSave
     * @param Closure $afterSave
     * @param bool    $force          Forces saving invalid data.
     *
     * @return bool
     * @see Ardent::save()
     * @see Ardent::forceSave()
     */
    protected function internalSave(array $rules = array(),
        array $customMessages = array(),
        array $options = array(),
        Closure $beforeSave = null,
        Closure $afterSave = null,
        $force = false
    ) {
        list($rules, $options) = $this->fixedArgsForSave($rules, $options);

        if ($beforeSave) {
            self::saving($beforeSave);
        }
        if ($afterSave) {
            self::saved($afterSave);
        }

        $valid = $this->validateUniques($rules, $customMessages);

        if ($force || $valid) {
            return $this->performSave($options);
        } else {
            return false;
        }
    }

    /**
     * Save the model to the database.
     *
     * @param array   $rules
     * @param array   $customMessages
     * @param array   $options
     * @param Closure $beforeSave
     * @param Closure $afterSave
     *
     * @return bool
     * @see Ardent::forceSave()
     */
    public function save(array $rules = array(),
        array $customMessages = array(),
        array $options = array(),
        Closure $beforeSave = null,
        Closure $afterSave = null
    ) {
        return $this->internalSave($rules, $customMessages, $options, $beforeSave, $afterSave, false);
    }

    /**
     * Force save the model even if validation fails.
     *
     * @param array   $rules
     * @param array   $customMessages
     * @param array   $options
     * @param Closure $beforeSave
     * @param Closure $afterSave
     * @return bool
     * @see Ardent::save()
     */
    public function forceSave(array $rules = array(),
        array $customMessages = array(),
        array $options = array(),
        Closure $beforeSave = null,
        Closure $afterSave = null
    ) {
        return $this->internalSave($rules, $customMessages, $options, $beforeSave, $afterSave, true);
    }

    /**
     * Add the basic purge filters
     *
     * @return void
     */
    protected function addBasicPurgeFilters() {
        if ($this->purgeFiltersInitialized) {
            return;
        }

        $this->purgeFilters[] = function ($attributeKey) {
            // disallow password confirmation fields
            if (Str::endsWith($attributeKey, '_confirmation')) {
                return false;
            }

            // "_method" is used by Illuminate\Routing\Router to simulate custom HTTP verbs
            if (strcmp($attributeKey, '_method') === 0) {
                return false;
            }

            // "_token" is used by Illuminate\Html\FormBuilder to add CSRF protection
            if (strcmp($attributeKey, '_token') === 0) {
                return false;
            }

            return true;
        };

        $this->purgeFiltersInitialized = true;
    }

    /**
     * Removes redundant attributes from model
     *
     * @param array $array Input array
     * @return array
     */
    protected function purgeArray(array $array = array()) {

        $result = array();
        $keys   = array_keys($array);

        $this->addBasicPurgeFilters();

        if (!empty($keys) && !empty($this->purgeFilters)) {
            foreach ($keys as $key) {
                $allowed = true;

                foreach ($this->purgeFilters as $filter) {
                    $allowed = $filter($key);

                    if (!$allowed) {
                        break;
                    }
                }

                if ($allowed) {
                    $result[$key] = $array[$key];
                }
            }
        }

        return $result;
    }

    /**
     * Saves the model instance to database. If necessary, it will purge the model attributes
     * of unnecessary fields. It will also replace plain-text password fields with their hashes.
     *
     * @param array $options
     * @return bool
     */
    protected function performSave(array $options) {

        if ($this->autoPurgeRedundantAttributes) {
            $this->attributes = $this->purgeArray($this->getAttributes());
        }

        if ($this->autoHashPasswordAttributes) {
            $this->attributes = $this->hashPasswordAttributes($this->getAttributes(), static::$passwordAttributes);
        }

        return parent::save($options);
    }

    /**
     * Get validation error message collection for the Model
     *
     * @return \Illuminate\Support\MessageBag
     */
    public function errors() {
        return $this->validationErrors;
    }

    /**
     * Hashes the password, working without the Hash facade if this is an instance outside of Laravel.
     * @param $value
     * @return string
     */
    protected function hashPassword($value) {
        return self::$external? self::$hasher->make($value) : Hash::make($value);
    }

    /**
     * Automatically replaces all plain-text password attributes (listed in $passwordAttributes)
     * with hash checksum.
     *
     * @param array $attributes
     * @param array $passwordAttributes
     * @return array
     */
    protected function hashPasswordAttributes(array $attributes = array(), array $passwordAttributes = array()) {

        if (empty($passwordAttributes) || empty($attributes)) {
            return $attributes;
        }

        $result = array();
        foreach ($attributes as $key => $value) {

            if (in_array($key, $passwordAttributes) && !is_null($value)) {
                if ($value != $this->getOriginal($key)) {
                    $result[$key] = $this->hashPassword($value);
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Appends the model ID to the 'unique' rules given. The resulting array can
     * then be fed to a Ardent save so that unchanged values don't flag a validation
     * issue. It can also be used with {@link Illuminate\Foundation\Http\FormRequest}
     * to painlessly validate model requests.
     * Rules can be in either strings with pipes or arrays, but the returned rules
     * are in arrays.
     * @param array $rules
     * @return array Rules with exclusions applied
     */
    public function buildUniqueExclusionRules(array $rules = array()) {

        if (!count($rules))
          $rules = static::$rules;

        foreach ($rules as $field => &$ruleset) {
            // If $ruleset is a pipe-separated string, switch it to array
            $ruleset = (is_string($ruleset))? explode('|', $ruleset) : $ruleset;

            foreach ($ruleset as &$rule) {
                if (strpos($rule, 'unique:') === 0) {
                    // Stop splitting at 4 so final param will hold optional where clause
                    $params = explode(',', $rule, 4);

                    $uniqueRules = array();

                    // Append table name if needed
                    $table = explode(':', $params[0]);
                    if (count($table) == 1) {
                        $uniqueRules[1] = $this->getTable();
                    } else {
                        $uniqueRules[1] = $table[1];
                    }

                    // Append field name if needed
                    if (count($params) == 1) {
                        $uniqueRules[2] = $field;
                    } else {
                        $uniqueRules[2] = $params[1];
                    }

                    if (isset($this->primaryKey)) {
                        if (isset($this->{$this->primaryKey})) {
                            $uniqueRules[3] = $this->{$this->primaryKey};

                            // If optional where rules are passed, append them otherwise use primary key
                            $uniqueRules[4] = isset($params[3])? $params[3] : $this->primaryKey;
                        }
                    } else {
                        if (isset($this->id)) {
                            $uniqueRules[3] = $this->id;
                        }
                    }

                    $rule = 'unique:'.implode(',', $uniqueRules);
                }
            }
        }

        return $rules;
    }

    /**
     * Update a model, but filter uniques first to ensure a unique validation rule
     * does not fire
     *
     * @param array   $rules
     * @param array   $customMessages
     * @param array   $options
     * @param Closure $beforeSave
     * @param Closure $afterSave
     * @return bool
     */
    public function updateUniques(array $rules = array(),
        array $customMessages = array(),
        array $options = array(),
        Closure $beforeSave = null,
        Closure $afterSave = null
    ) {
        $rules = $this->buildUniqueExclusionRules($rules);

        return $this->save($rules, $customMessages, $options, $beforeSave, $afterSave);
    }

    /**
     * Validates a model with unique rules properly treated.
     *
     * @param array $rules Validation rules
     * @param array $customMessages Custom error messages
     * @return bool
     * @see Ardent::validate()
     */
    public function validateUniques(array $rules = array(), array $customMessages = array()) {
        $rules = $this->buildUniqueExclusionRules($rules);
        return $this->validate($rules, $customMessages);
    }

    /**
     * Returns the validator object created after {@link validate()}.
     * @return \Illuminate\Validation\Validator
     */
    public function getValidator() {
        return $this->validator;
    }

    /**
     * 本来のsaveでは$rulesの箇所に$optionsが渡されるので
     *
     * @param  array $rules
     * @param  array $options
     * @return array [$rules, $options]
     */
    protected function fixedArgsForSave($rules, $options)
    {
        if(empty($options)){
            $collection = collect($options);
            if($collection->keys()->diff(['timestamps', 'touch'])->isEmpty()){
                $options = $rules;
                $rules = [];
            }
        }

        return [$rules, $options];
    }
}
