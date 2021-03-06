<?php

namespace Sjdaws\Vocal;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\MessageBag;

/**
 * Extends model
 *
 * @property int $id
 * @method void afterCreate()
 * @method void afterDelete()
 * @method void afterSave()
 * @method void afterUpdate()
 * @method void afterValidate()
 * @method void beforeCreate()
 * @method void beforeDelete()
 * @method void beforeSave()
 * @method void beforeUpdate()
 * @method void beforeValidate()
 * @method bool forceSave()
 * @method string getParentKey()
 * @method string getPlainForeignKey()
 */

class Vocal extends Model
{
    /**
     * The dataset we're currently validating
     *
     * @var array
     */
    private $data;

    /**
     * Store the differences for updated fields
     *
     * @var array
     */
    protected $diff = array();

    /**
     * Fields to ignore when comparing diff
     *
     * @var array
     */
    protected $diffIgnore = array();

    /**
     * The message bag instance containing validation error messages
     *
     * @var Illuminate\Support\MessageBag
     */
    protected $errors;

    /**
     * Fill all models from input by default
     *
     * @var bool
     */
    protected $fillFromInput = true;

    /**
     * The hash class for hashing attributes
     *
     * @var Illuminate\Hashing\HasherInterface
     */
    private $hasher;

    /**
     * Hash attributes automatically on save
     *
     * @var array
     */
    protected $hashAttributes = array();

    /**
     * The folder which language files are stored in
     *
     * @var string
     */
    protected $languageFolder = 'validation';

    /**
     * The key within the language file where validation messages are stored
     *
     * @var string
     */
    protected $languageKey = null;

    /**
     * The rules to be applied to the data
     *
     * @var array
     */
    public $rules = array();

    /**
     * Determine whether to use listeners or direct calls for hooks
     *
     * @var bool
     */
    protected static $useHookListeners = false;

    /**
     * Determine whether the model has been hydrated
     *
     * @var bool
     */
    private $_hydratedByVocal = false;

    /**
     * Determine whether the model has been validated
     *
     * @var bool
     */
    private $_validatedByVocal = false;

    /**
     * Create a new model instance
     *
     * @param array $attributes
     * @return Sjdaws\Vocal\Vocal
     */
    public function __construct(array $attributes = array())
    {
        parent::__construct($attributes);
        $this->errors = new MessageBag;

        // Boot model to enable hooks
        if ($this->useHookListeners) self::boot();

        $this->hasher = new BcryptHasher;
    }

    /**
     * Override to boot method of each model to attach before and after hooks
     *
     * @see Illuminate\Database\Eloquent\Model::boot()
     * @return void
     */
    public static function boot()
    {
        parent::boot();

        // Don't add hooks if we aren't using them
        if ( ! self::$useHookListeners) return;

        $hooks    = array('before' => 'ing', 'after' => 'ed');
        $radicals = array('sav', 'validat', 'creat', 'updat', 'delet');

        foreach ($radicals as $rad)
        {
            foreach ($hooks as $hook => $event)
            {
                $method = $hook . ucfirst($rad) . 'e';

                if (method_exists(get_called_class(), $method))
                {
                    $eventMethod = $rad . $event;

                    self::$eventMethod(function($model) use ($method)
                    {
                        return $model->$method($model);
                    });
                }
            }
        }
    }

    /**
     * Build validation rules
     * - This function replaces ~attributes with their values
     * - ~table will be replaced with the table name and ~field will be replaced with the field name
     *   providing there is no column named table or field respectively
     * - This function also auto builds 'unique' rules when the a rule is just passed as 'unique'
     *
     * @param array $rules
     * @return array
     */
    private function buildValidationRules($rules)
    {
        // Replace any tilde rules with the correct attribute
        foreach ($rules as $field => &$ruleset)
        {
            // If rules are pipe delimited, change them to array
            if (is_string($ruleset)) $ruleset = explode('|', $ruleset);

            foreach ($ruleset as &$rule)
            {
                // Seperate rule type from rule
                if (strpos($rule, ':') !== false) list($type, $parameters) = explode(':', $rule, 2);
                else
                {
                    $type = $rule;
                    $parameters = null;
                }

                // Make parameters into an array
                $parameters = (strpos($parameters, ',') > 0) ? explode(',', $parameters) : array($parameters);

                // Process each parameter
                foreach ($parameters as &$parameter)
                {
                    if (strpos($parameter, '~') !== false)
                    {
                        // Replace ~table and ~field unless we have an attribute with the same name
                        if ($parameter == '~table' && ! $this->{str_replace('~', '', $parameter)}) $parameter = $this->getTable();
                        if ($parameter == '~field' && ! $this->{str_replace('~', '', $parameter)}) $parameter = $field;

                        // Replace with attribute if we haven't replaced yet
                        if (strpos($parameter, '~') !== false) $parameter = $this->{str_replace('~', '', $parameter)};
                    }
                }

                // If we have a unique rule, make sure it's built correctly
                if ($type == 'unique')
                {
                    $uniqueRule = array();

                    // Build up the rule to make sure it's correct
                    if (isset($parameters[0])) $uniqueRule[] = $parameters[0];
                    else $uniqueRule[] = $this->getTable();

                    // Field name second
                    if (isset($parameters[1])) $uniqueRule[] = $parameters[1];
                    else $uniqueRule[] = $field;

                    // Make sure we ignore the current record
                    if (isset($this->primaryKey))
                    {
                        $uniqueRule[] = (isset($parameters[2])) ? $parameters[2] : $this->{$this->primaryKey};
                        $uniqueRule[] = (isset($parameters[3])) ? $parameters[3] : $this->primaryKey;
                    }
                    else
                    {
                        $uniqueRule[] = (isset($parameters[2])) ? $parameters[2] : $this->id;
                        $uniqueRule[] = (isset($parameters[3])) ? $parameters[3] : 'id';
                    }

                    // If we have exactly 6 parameters then we use the where clause field to fill the exclusion
                    if (count($parameters) > 4) for ($i = 4; $i < count($parameters); ++$i) $uniqueRule[] = $parameters[$i];

                    $parameters = $uniqueRule;
                }

                // Rebuild rule
                $rule = $type;

                // Don't try and join parameters unless we have some
                if ( ! $parameters || ! count(array_filter($parameters))) continue;

                if (is_array($parameters) && count($parameters)) $rule .= ':' . implode(',', $parameters);
                else $rule .= ':' . $parameters;
            }
        }

        return $rules;
    }

    /**
     * Delete a model
     *
     * @return bool|null
     */
    public function delete()
    {
        if (method_exists(get_called_class(), 'beforeDelete')) $this->beforeDelete();

        $result = parent::delete();

        if (method_exists(get_called_class(), 'afterDelete')) $this->afterDelete();

        return $result;
    }

    /**
     * Determine the difference in a model
     *
     * @return array
     */
    private function determineDiff()
    {
        // Determine what we're working with
        $attributes = ( ! count($this->getOriginal())) ? $this->getAttributes() : $this->getOriginal();
        $new = ( ! count($this->getOriginal()));

        // Compare each attribute
        foreach ($attributes as $key => $value)
        {
            // Skip vocal internal keys or ignored keys
            if ($key == '_hydratedByVocal' || $key == '_validatedByVocal' || in_array($key, $this->diffIgnore)) continue;

            if ($new) $this->diff[$key] = array('new'  => $value);
            else if ($value != $this->$key)
            {
                $this->diff[$key] = array(
                    'original' => $value,
                    'updated'  => $this->$key
                );
            }
        }
    }

    /**
     * Get the difference between an original model, and updated model
     *
     * @return array
     */
    public function diff()
    {
        return $this->diff;
    }

    /**
     * Return errors in MessageBag format
     *
     * @return Illuminate\Support\MessageBag
     */
    public function errorBag()
    {
        return $this->errors;
    }

    /**
     * Return the errors as an array
     *
     * @param string $filter [= null]
     * @return array
     */
    public function errors($filter = null)
    {
        // Create an array to hold errors
        $messages = array();

        // If we have no errors, abort
        if ( ! $this->errors->count()) return $messages;

        foreach ($this->errors->toArray() as $key => $error)
        {
            $messages[$key] = $this->extractErrors($error);
        }

        // Return a specific set of messages if asked
        return array_get($messages, $filter);
    }

    /**
     * Recursively extract error messages
     *
     * @param array $error
     * @return array
     */
    private function extractErrors($error)
    {
        $messages = array();

        foreach ($error as $key => $errors)
        {
            // If error is a MessageBag, extract errors
            if ($errors instanceof MessageBag) return $this->extractErrors($errors->toArray());

            // If we have an array of errors preserve key
            if (is_array($errors))
            {
                $messages[$key] = $this->extractErrors($errors);
                continue;
            }

            // We must be down to a single error, just return it
            return $errors;
        }

        return $messages;
    }

    /**
     * Save a model regardless of validation result
     *
     * @param array $data
     * @param Closure $before
     * @param Closure $after
     * @return bool
     */
    public function forceSave($data = array(), Closure $before = null, Closure $after = null)
    {
        // Force empty ruleset for this model
        return $this->save(array(null), array(null), $data, $before, $after);
    }

    /**
     * Force save a record, then delete it
     *
     * @param array $data
     * @param Closure $before
     * @param Closure $after
     * @return bool
     */
    public function forceSaveAndDelete($data = array(), Closure $before = null, Closure $after = null)
    {
        $result = $this->forceSave($data, $before, $after);

        $this->delete();

        return $result;
    }

    /**
     * Get the observable event names
     *
     * @return array
     */
    public function getObservableEvents()
    {
        return array_merge(
            parent::getObservableEvents(),
            array('validating', 'validated')
        );
    }

    /**
     * Get data for a relationship
     *
     * @param string $relationship
     * @param array $conditions
     * @param array $rules
     * @param array $messages
     * @return array
     */
    private function getRelationshipData($relationship, $conditions, $rules, $messages)
    {
        return array($this->getRelationshipDataFromArray($relationship, $conditions), $this->getRelationshipDataFromArray($relationship, $rules), $this->getRelationshipDataFromArray($relationship, $messages));
    }

    /**
     * Extract data from a relationship array
     *
     * @param string $relationship
     * @param array $data
     * @return array
     */
    private function getRelationshipDataFromArray($relationship, $data)
    {
        // Determine model class
        $modelClass = Str::camel($relationship);

        if (isset($data[$relationship]) || isset($data[$modelClass])) return (isset($data[$relationship])) ? $data[$relationship] : $data[$modelClass];

        return array();
    }

    /**
     * Check whether passed data contains a relationship or not
     *
     * @param array $data
     * @param array $conditions
     * @return array
     */
    private function getRelationships($data, $conditions)
    {
        $relationships = array();

        // Loop through input, and check whether any key is a valid relationship
        foreach ($data as $model => $value)
        {
            // If the values isn't an array, it can't be a child, skip
            if ( ! is_array($value)) continue;

            // Make sure snake_case models are resolved to camelCase functions
            $modelClass = Str::camel($model);

            // Check if we're excluding this model
            if ((isset($conditions['except']) && (in_array($model, $conditions['except']) || in_array($modelClass, $conditions['except']))) || (isset($conditions['only']) && ( ! in_array($modelClass, $conditions['only']) && ! in_array($modelClass, $conditions['only'])))) continue;

            // Check if method exists
            if (method_exists($this, $model))
            {
                $instance = $this->$modelClass();

                // If the function is a relationship instance, assume it's legit
                if (
                        $instance instanceof BelongsTo ||
                        $instance instanceof BelongsToMany ||
                        $instance instanceof HasMany ||
                        $instance instanceof HasManyThrough ||
                        $instance instanceof HasOne ||
                        $instance instanceof MorphMany ||
                        $instance instanceof MorphOne ||
                        $instance instanceof MorphTo
                    )
                    $relationships[$model] = $this->getRelationshipType($instance);
            }
        }

        return $relationships;
    }

    /**
     * Determine what type of relationship we're working with
     *
     * @param object $model
     * @return string
     */
    private function getRelationshipType($model)
    {
        return (
            $model instanceof BelongsTo ||
            $model instanceof HasOne ||
            $model instanceof MorphOne ||
            $model instanceof MorphTo
        ) ? 'one' : 'many';
    }

    /**
     * Fill a record and set a flag to save it's been filled
     *
     * @param array $data
     * @return void
     */
    private function hydrateModel($data)
    {
        $this->fill($data);
        $this->_hydratedByVocal = true;
    }

    /**
     * Load custom error messages from a language file
     *
     * @return void
     */
    public function loadCustomMessages($rules)
    {
        // If we don't have any rules, don't do anything
        if ( ! count($rules)) return;

        // Determine file for validation messages
        $file = $this->languageFolder . '/' . str_replace('\\', '/', get_called_class()) . '.';

        $messages = array();

        // Process each rule
        foreach($rules as $field => $ruleset)
        {
            // If rules are pipe delimited, change them to array
            if ( ! is_array($ruleset)) $ruleset = explode('|', $ruleset);

            foreach ($ruleset as $rule)
            {
                // Remove extra parameters if we have them
                if (strpos($rule, ':')) $rule = current(explode(':', $rule));

                $key = implode('.', array($field, $rule));
                $fileKey = ($this->languageKey) ? $this->languageKey . '.' . $key : $key;

                // We have a couple of options where language files could be saved,
                // try: Model.php and model.php, as well as Model_Model.php and Model/Model.php
                $attempts = array($file . $fileKey, Str::lower($file) . $fileKey, str_replace('_', '/', $file) . $fileKey, Str::lower(str_replace('_', '/', $file)) . $fileKey);

                foreach ($attempts as $attempt)
                {
                    if (Lang::has($attempt))
                    {
                        $messages[$key] = Lang::get($attempt);
                        continue;
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Create a sentence from random dictionary words
     *
     * @param int $length [= 1]
     * @return string
     */
    public static function randomSentence($length = 1)
    {
        $wordlist = array('ability', 'able', 'aboard', 'about', 'above', 'accept', 'accident', 'according', 'account', 'accurate', 'acres', 'across', 'act', 'action', 'active', 'activity', 'actual', 'actually', 'add', 'addition', 'additional', 'adjective', 'adult', 'adventure', 'advice', 'affect', 'afraid', 'after', 'afternoon', 'again', 'against', 'age', 'ago', 'agree', 'ahead', 'aid', 'air', 'airplane', 'alike', 'alive', 'all', 'allow', 'almost', 'alone', 'along', 'aloud', 'alphabet', 'already', 'also', 'although', 'am', 'among', 'amount', 'ancient', 'angle', 'angry', 'animal', 'announced', 'another', 'answer', 'ants', 'any', 'anybody', 'anyone', 'anything', 'anyway', 'anywhere', 'apart', 'apartment', 'appearance', 'apple', 'applied', 'appropriate', 'are', 'area', 'arm', 'army', 'around', 'arrange', 'arrangement', 'arrive', 'arrow', 'art', 'article', 'as', 'aside', 'ask', 'asleep', 'at', 'ate', 'atmosphere', 'atom', 'atomic', 'attached', 'attack', 'attempt', 'attention', 'audience', 'author', 'automobile', 'available', 'average', 'avoid', 'aware', 'away', 'baby', 'back', 'bad', 'badly', 'bag', 'balance', 'ball', 'balloon', 'band', 'bank', 'bar', 'bare', 'bark', 'barn', 'base', 'baseball', 'basic', 'basis', 'basket', 'bat', 'battle', 'be', 'bean', 'bear', 'beat', 'beautiful', 'beauty', 'became', 'because', 'become', 'becoming', 'bee', 'been', 'before', 'began', 'beginning', 'begun', 'behavior', 'behind', 'being', 'believed', 'bell', 'belong', 'below', 'belt', 'bend', 'beneath', 'bent', 'beside', 'best', 'bet', 'better', 'between', 'beyond', 'bicycle', 'bigger', 'biggest', 'bill', 'birds', 'birth', 'birthday', 'bit', 'bite', 'black', 'blank', 'blanket', 'blew', 'blind', 'block', 'blood', 'blow', 'blue', 'board', 'boat', 'body', 'bone', 'book', 'border', 'born', 'both', 'bottle', 'bottom', 'bound', 'bow', 'bowl', 'box', 'boy', 'brain', 'branch', 'brass', 'brave', 'bread', 'break', 'breakfast', 'breath', 'breathe', 'breathing', 'breeze', 'brick', 'bridge', 'brief', 'bright', 'bring', 'broad', 'broke', 'broken', 'brother', 'brought', 'brown', 'brush', 'buffalo', 'build', 'building', 'built', 'buried', 'burn', 'burst', 'bus', 'bush', 'business', 'busy', 'but', 'butter', 'buy', 'by', 'cabin', 'cage', 'cake', 'call', 'calm', 'came', 'camera', 'camp', 'can', 'canal', 'cannot', 'cap', 'capital', 'captain', 'captured', 'car', 'carbon', 'card', 'care', 'careful', 'carefully', 'carried', 'carry', 'case', 'cast', 'castle', 'cat', 'catch', 'cattle', 'caught', 'cause', 'cave', 'cell', 'cent', 'center', 'central', 'century', 'certain', 'certainly', 'chain', 'chair', 'chamber', 'chance', 'change', 'changing', 'chapter', 'character', 'characteristic', 'charge', 'chart', 'check', 'cheese', 'chemical', 'chest', 'chicken', 'chief', 'child', 'children', 'choice', 'choose', 'chose', 'chosen', 'church', 'circle', 'circus', 'citizen', 'city', 'class', 'classroom', 'claws', 'clay', 'clean', 'clear', 'clearly', 'climate', 'climb', 'clock', 'close', 'closely', 'closer', 'cloth', 'clothes', 'clothing', 'cloud', 'club', 'coach', 'coal', 'coast', 'coat', 'coffee', 'cold', 'collect', 'college', 'colony', 'color', 'column', 'combination', 'combine', 'come', 'comfortable', 'coming', 'command', 'common', 'community', 'company', 'compare', 'compass', 'complete', 'completely', 'complex', 'composed', 'composition', 'compound', 'concerned', 'condition', 'congress', 'connected', 'consider', 'consist', 'consonant', 'constantly', 'construction', 'contain', 'continent', 'continued', 'contrast', 'control', 'conversation', 'cook', 'cookies', 'cool', 'copper', 'copy', 'corn', 'corner', 'correct', 'correctly', 'cost', 'cotton', 'could', 'count', 'country', 'couple', 'courage', 'course', 'court', 'cover', 'cow', 'cowboy', 'crack', 'cream', 'create', 'creature', 'crew', 'crop', 'cross', 'crowd', 'cry', 'cup', 'curious', 'current', 'curve', 'customs', 'cut', 'cutting', 'daily', 'damage', 'dance', 'danger', 'dangerous', 'dark', 'darkness', 'date', 'daughter', 'dawn', 'day', 'dead', 'deal', 'dear', 'death', 'decide', 'declared', 'deep', 'deeply', 'deer', 'definition', 'degree', 'depend', 'depth', 'describe', 'desert', 'design', 'desk', 'detail', 'determine', 'develop', 'development', 'diagram', 'diameter', 'did', 'die', 'differ', 'difference', 'different', 'difficult', 'difficulty', 'dig', 'dinner', 'direct', 'direction', 'directly', 'dirt', 'dirty', 'disappear', 'discover', 'discovery', 'discuss', 'discussion', 'disease', 'dish', 'distance', 'distant', 'divide', 'division', 'do', 'doctor', 'does', 'dog', 'doing', 'doll', 'dollar', 'done', 'donkey', 'door', 'dot', 'double', 'doubt', 'down', 'dozen', 'draw', 'drawn', 'dream', 'dress', 'drew', 'dried', 'drink', 'drive', 'driven', 'driver', 'driving', 'drop', 'dropped', 'drove', 'dry', 'duck', 'due', 'dug', 'dull', 'during', 'dust', 'duty', 'each', 'eager', 'ear', 'earlier', 'early', 'earn', 'earth', 'easier', 'easily', 'east', 'easy', 'eat', 'eaten', 'edge', 'education', 'effect', 'effort', 'egg', 'eight', 'either', 'electric', 'electricity', 'element', 'elephant', 'eleven', 'else', 'empty', 'end', 'enemy', 'energy', 'engine', 'engineer', 'enjoy', 'enough', 'enter', 'entire', 'entirely', 'environment', 'equal', 'equally', 'equator', 'equipment', 'escape', 'especially', 'essential', 'establish', 'even', 'evening', 'event', 'eventually', 'ever', 'every', 'everybody', 'everyone', 'everything', 'everywhere', 'evidence', 'exact', 'exactly', 'examine', 'example', 'excellent', 'except', 'exchange', 'excited', 'excitement', 'exciting', 'exclaimed', 'exercise', 'exist', 'expect', 'experience', 'experiment', 'explain', 'explanation', 'explore', 'express', 'expression', 'extra', 'eye', 'face', 'facing', 'fact', 'factor', 'factory', 'failed', 'fair', 'fairly', 'fall', 'fallen', 'familiar', 'family', 'famous', 'far', 'farm', 'farmer', 'farther', 'fast', 'fastened', 'faster', 'fat', 'father', 'favorite', 'fear', 'feathers', 'feature', 'fed', 'feed', 'feel', 'feet', 'fell', 'fellow', 'felt', 'fence', 'few', 'fewer', 'field', 'fierce', 'fifteen', 'fifth', 'fifty', 'fight', 'fighting', 'figure', 'fill', 'film', 'final', 'finally', 'find', 'fine', 'finest', 'finger', 'finish', 'fire', 'fireplace', 'firm', 'first', 'fish', 'five', 'fix', 'flag', 'flame', 'flat', 'flew', 'flies', 'flight', 'floating', 'floor', 'flow', 'flower', 'fly', 'fog', 'folks', 'follow', 'food', 'foot', 'football', 'for', 'force', 'foreign', 'forest', 'forget', 'forgot', 'forgotten', 'form', 'former', 'fort', 'forth', 'forty', 'forward', 'fought', 'found', 'four', 'fourth', 'fox', 'frame', 'free', 'freedom', 'frequently', 'fresh', 'friend', 'friendly', 'frighten', 'frog', 'from', 'front', 'frozen', 'fruit', 'fuel', 'full', 'fully', 'fun', 'function', 'funny', 'fur', 'furniture', 'further', 'future', 'gain', 'game', 'garage', 'garden', 'gas', 'gasoline', 'gate', 'gather', 'gave', 'general', 'generally', 'gentle', 'gently', 'get', 'getting', 'giant', 'gift', 'girl', 'give', 'given', 'giving', 'glad', 'glass', 'globe', 'go', 'goes', 'gold', 'golden', 'gone', 'good', 'goose', 'got', 'government', 'grabbed', 'grade', 'gradually', 'grain', 'grandfather', 'grandmother', 'graph', 'grass', 'gravity', 'gray', 'great', 'greater', 'greatest', 'greatly', 'green', 'grew', 'ground', 'group', 'grow', 'grown', 'growth', 'guard', 'guess', 'guide', 'gulf', 'gun', 'habit', 'had', 'hair', 'half', 'halfway', 'hall', 'hand', 'handle', 'handsome', 'hang', 'happen', 'happened', 'happily', 'happy', 'harbor', 'hard', 'harder', 'hardly', 'has', 'hat', 'have', 'having', 'hay', 'he', 'headed', 'heading', 'health', 'heard', 'hearing', 'heart', 'heat', 'heavy', 'height', 'held', 'hello', 'help', 'helpful', 'her', 'herd', 'here', 'herself', 'hidden', 'hide', 'high', 'higher', 'highest', 'highway', 'hill', 'him', 'himself', 'his', 'history', 'hit', 'hold', 'hole', 'hollow', 'home', 'honor', 'hope', 'horn', 'horse', 'hospital', 'hot', 'hour', 'house', 'how', 'however', 'huge', 'human', 'hundred', 'hung', 'hungry', 'hunt', 'hunter', 'hurried', 'hurry', 'hurt', 'husband', 'ice', 'idea', 'identity', 'if', 'ill', 'image', 'imagine', 'immediately', 'importance', 'important', 'impossible', 'improve', 'in', 'inch', 'include', 'including', 'income', 'increase', 'indeed', 'independent', 'indicate', 'individual', 'industrial', 'industry', 'influence', 'information', 'inside', 'instance', 'instant', 'instead', 'instrument', 'interest', 'interior', 'into', 'introduced', 'invented', 'involved', 'iron', 'is', 'island', 'it', 'its', 'itself', 'jack', 'jar', 'jet', 'job', 'join', 'joined', 'journey', 'joy', 'judge', 'jump', 'jungle', 'just', 'keep', 'kept', 'key', 'kids', 'kill', 'kind', 'kitchen', 'knew', 'knife', 'know', 'knowledge', 'known', 'label', 'labor', 'lack', 'lady', 'laid', 'lake', 'lamp', 'land', 'language', 'large', 'larger', 'largest', 'last', 'late', 'later', 'laugh', 'law', 'lay', 'layers', 'lead', 'leader', 'leaf', 'learn', 'least', 'leather', 'leave', 'leaving', 'led', 'left', 'leg', 'length', 'lesson', 'let', 'letter', 'level', 'library', 'lie', 'life', 'lift', 'light', 'like', 'likely', 'limited', 'line', 'lion', 'lips', 'liquid', 'list', 'listen', 'little', 'live', 'living', 'load', 'local', 'locate', 'location', 'log', 'lonely', 'long', 'longer', 'look', 'loose', 'lose', 'loss', 'lost', 'lot', 'loud', 'love', 'lovely', 'low', 'lower', 'luck', 'lucky', 'lunch', 'lungs', 'lying', 'machine', 'machinery', 'mad', 'made', 'magic', 'magnet', 'mail', 'main', 'mainly', 'major', 'make', 'making', 'man', 'managed', 'manner', 'manufacturing', 'many', 'map', 'mark', 'market', 'married', 'mass', 'massage', 'master', 'material', 'mathematics', 'matter', 'may', 'maybe', 'me', 'meal', 'mean', 'means', 'meant', 'measure', 'meat', 'medicine', 'meet', 'melted', 'member', 'memory', 'men', 'mental', 'merely', 'met', 'metal', 'method', 'mice', 'middle', 'might', 'mighty', 'mile', 'military', 'milk', 'mill', 'mind', 'mine', 'minerals', 'minute', 'mirror', 'missing', 'mission', 'mistake', 'mix', 'mixture', 'model', 'modern', 'molecular', 'moment', 'money', 'monkey', 'month', 'mood', 'moon', 'more', 'morning', 'most', 'mostly', 'mother', 'motion', 'motor', 'mountain', 'mouse', 'mouth', 'move', 'movement', 'movie', 'moving', 'mud', 'muscle', 'music', 'musical', 'must', 'my', 'myself', 'mysterious', 'nails', 'name', 'nation', 'national', 'native', 'natural', 'naturally', 'nature', 'near', 'nearby', 'nearer', 'nearest', 'nearly', 'necessary', 'neck', 'needed', 'needle', 'needs', 'negative', 'neighbor', 'neighborhood', 'nervous', 'nest', 'never', 'new', 'news', 'newspaper', 'next', 'nice', 'night', 'nine', 'no', 'nobody', 'nodded', 'noise', 'none', 'noon', 'nor', 'north', 'nose', 'not', 'note', 'noted', 'nothing', 'notice', 'noun', 'now', 'number', 'numeral', 'nuts', 'object', 'observe', 'obtain', 'occasionally', 'occur', 'ocean', 'of', 'off', 'offer', 'office', 'officer', 'official', 'oil', 'old', 'older', 'oldest', 'on', 'once', 'one', 'only', 'onto', 'open', 'operation', 'opinion', 'opportunity', 'opposite', 'or', 'orange', 'orbit', 'order', 'ordinary', 'organization', 'organized', 'origin', 'original', 'other', 'ought', 'our', 'ourselves', 'out', 'outer', 'outline', 'outside', 'over', 'own', 'owner', 'oxygen', 'pack', 'package', 'page', 'paid', 'pain', 'paint', 'pair', 'palace', 'pale', 'pan', 'paper', 'paragraph', 'parallel', 'parent', 'park', 'part', 'particles', 'particular', 'particularly', 'partly', 'parts', 'party', 'pass', 'passage', 'past', 'path', 'pattern', 'pay', 'peace', 'pen', 'pencil', 'people', 'per', 'percent', 'perfect', 'perfectly', 'perhaps', 'period', 'person', 'personal', 'pet', 'phrase', 'physical', 'piano', 'pick', 'picture', 'pictured', 'pie', 'piece', 'pig', 'pile', 'pilot', 'pine', 'pink', 'pipe', 'pitch', 'place', 'plain', 'plan', 'plane', 'planet', 'planned', 'planning', 'plant', 'plastic', 'plate', 'plates', 'play', 'pleasant', 'please', 'pleasure', 'plenty', 'plural', 'plus', 'pocket', 'poem', 'poet', 'poetry', 'point', 'pole', 'police', 'policeman', 'political', 'pond', 'pony', 'pool', 'poor', 'popular', 'population', 'porch', 'port', 'position', 'positive', 'possible', 'possibly', 'post', 'pot', 'potatoes', 'pound', 'pour', 'powder', 'power', 'powerful', 'practical', 'practice', 'prepare', 'present', 'president', 'press', 'pressure', 'pretty', 'prevent', 'previous', 'price', 'pride', 'primitive', 'principal', 'principle', 'printed', 'private', 'prize', 'probably', 'problem', 'process', 'produce', 'product', 'production', 'program', 'progress', 'promised', 'proper', 'properly', 'property', 'protection', 'proud', 'prove', 'provide', 'public', 'pull', 'pupil', 'pure', 'purple', 'purpose', 'push', 'put', 'putting', 'quarter', 'queen', 'question', 'quick', 'quickly', 'quiet', 'quietly', 'quite', 'rabbit', 'race', 'radio', 'railroad', 'rain', 'raise', 'ran', 'ranch', 'range', 'rapidly', 'rate', 'rather', 'raw', 'rays', 'reach', 'read', 'reader', 'ready', 'real', 'realize', 'rear', 'reason', 'recall', 'receive', 'recent', 'recently', 'recognize', 'record', 'red', 'refer', 'refused', 'region', 'regular', 'related', 'relationship', 'religious', 'remain', 'remarkable', 'remember', 'remove', 'repeat', 'replace', 'replied', 'report', 'represent', 'require', 'research', 'respect', 'rest', 'result', 'return', 'review', 'rhyme', 'rhythm', 'rice', 'rich', 'ride', 'riding', 'right', 'ring', 'rise', 'rising', 'river', 'road', 'roar', 'rock', 'rocket', 'rocky', 'rod', 'roll', 'roof', 'room', 'root', 'rope', 'rose', 'rough', 'round', 'route', 'row', 'rubbed', 'rubber', 'rule', 'ruler', 'run', 'running', 'rush', 'sad', 'saddle', 'safe', 'safety', 'said', 'sail', 'sale', 'salmon', 'salt', 'same', 'sand', 'sang', 'sat', 'satellites', 'satisfied', 'save', 'saved', 'saw', 'say', 'scale', 'scared', 'scene', 'school', 'science', 'scientific', 'scientist', 'score', 'screen', 'sea', 'search', 'season', 'seat', 'second', 'secret', 'section', 'see', 'seed', 'seeing', 'seems', 'seen', 'seldom', 'select', 'selection', 'sell', 'send', 'sense', 'sent', 'sentence', 'separate', 'series', 'serious', 'serve', 'service', 'sets', 'setting', 'settle', 'settlers', 'seven', 'several', 'shade', 'shadow', 'shake', 'shaking', 'shall', 'shallow', 'shape', 'share', 'sharp', 'she', 'sheep', 'sheet', 'shelf', 'shells', 'shelter', 'shine', 'shinning', 'ship', 'shirt', 'shoe', 'shoot', 'shop', 'shore', 'short', 'shorter', 'shot', 'should', 'shoulder', 'shout', 'show', 'shown', 'shut', 'sick', 'sides', 'sight', 'sign', 'signal', 'silence', 'silent', 'silk', 'silly', 'silver', 'similar', 'simple', 'simplest', 'simply', 'since', 'sing', 'single', 'sink', 'sister', 'sit', 'sitting', 'situation', 'six', 'size', 'skill', 'skin', 'sky', 'slabs', 'slave', 'sleep', 'slept', 'slide', 'slight', 'slightly', 'slip', 'slipped', 'slope', 'slow', 'slowly', 'small', 'smaller', 'smallest', 'smell', 'smile', 'smoke', 'smooth', 'snake', 'snow', 'so', 'soap', 'social', 'society', 'soft', 'softly', 'soil', 'solar', 'sold', 'soldier', 'solid', 'solution', 'solve', 'some', 'somebody', 'somehow', 'someone', 'something', 'sometime', 'somewhere', 'son', 'song', 'soon', 'sort', 'sound', 'source', 'south', 'southern', 'space', 'speak', 'special', 'species', 'specific', 'speech', 'speed', 'spell', 'spend', 'spent', 'spider', 'spin', 'spirit', 'spite', 'split', 'spoken', 'sport', 'spread', 'spring', 'square', 'stage', 'stairs', 'stand', 'standard', 'star', 'stared', 'start', 'state', 'statement', 'station', 'stay', 'steady', 'steam', 'steel', 'steep', 'stems', 'step', 'stepped', 'stick', 'stiff', 'still', 'stock', 'stomach', 'stone', 'stood', 'stop', 'stopped', 'store', 'storm', 'story', 'stove', 'straight', 'strange', 'stranger', 'straw', 'stream', 'street', 'strength', 'stretch', 'strike', 'string', 'strip', 'strong', 'stronger', 'struck', 'structure', 'struggle', 'stuck', 'student', 'studied', 'studying', 'subject', 'substance', 'success', 'successful', 'such', 'sudden', 'suddenly', 'sugar', 'suggest', 'suit', 'sum', 'summer', 'sun', 'sunlight', 'supper', 'supply', 'support', 'suppose', 'sure', 'surface', 'surprise', 'surrounded', 'swam', 'sweet', 'swept', 'swim', 'swimming', 'swing', 'swung', 'syllable', 'symbol', 'system', 'table', 'tail', 'take', 'taken', 'tales', 'talk', 'tall', 'tank', 'tape', 'task', 'taste', 'taught', 'tax', 'tea', 'teach', 'teacher', 'team', 'tears', 'teeth', 'telephone', 'television', 'tell', 'temperature', 'ten', 'tent', 'term', 'terrible', 'test', 'than', 'thank', 'that', 'thee', 'them', 'themselves', 'then', 'theory', 'there', 'therefore', 'these', 'they', 'thick', 'thin', 'thing', 'think', 'third', 'thirty', 'this', 'those', 'thou', 'though', 'thought', 'thousand', 'thread', 'three', 'threw', 'throat', 'through', 'throughout', 'throw', 'thrown', 'thumb', 'thus', 'thy', 'tide', 'tie', 'tight', 'tightly', 'till', 'time', 'tin', 'tiny', 'tip', 'tired', 'title', 'to', 'tobacco', 'today', 'together', 'told', 'tomorrow', 'tone', 'tongue', 'tonight', 'too', 'took', 'tool', 'top', 'topic', 'torn', 'total', 'touch', 'toward', 'tower', 'town', 'toy', 'trace', 'track', 'trade', 'traffic', 'trail', 'train', 'transportation', 'trap', 'travel', 'treated', 'tree', 'triangle', 'tribe', 'trick', 'tried', 'trip', 'troops', 'tropical', 'trouble', 'truck', 'trunk', 'truth', 'try', 'tube', 'tune', 'turn', 'twelve', 'twenty', 'twice', 'two', 'type', 'typical', 'uncle', 'under', 'underline', 'understanding', 'unhappy', 'union', 'unit', 'universe', 'unknown', 'unless', 'until', 'unusual', 'up', 'upon', 'upper', 'upward', 'us', 'use', 'useful', 'using', 'usual', 'usually', 'valley', 'valuable', 'value', 'vapor', 'variety', 'various', 'vast', 'vegetable', 'verb', 'vertical', 'very', 'vessels', 'victory', 'view', 'village', 'visit', 'visitor', 'voice', 'volume', 'vote', 'vowel', 'voyage', 'wagon', 'wait', 'walk', 'wall', 'want', 'war', 'warm', 'warn', 'was', 'wash', 'waste', 'watch', 'water', 'wave', 'way', 'we', 'weak', 'wealth', 'wear', 'weather', 'week', 'weigh', 'weight', 'welcome', 'well', 'went', 'were', 'west', 'western', 'wet', 'whale', 'what', 'whatever', 'wheat', 'wheel', 'when', 'whenever', 'where', 'wherever', 'whether', 'which', 'while', 'whispered', 'whistle', 'white', 'who', 'whole', 'whom', 'whose', 'why', 'wide', 'widely', 'wife', 'wild', 'will', 'willing', 'win', 'wind', 'window', 'wing', 'winter', 'wire', 'wise', 'wish', 'with', 'within', 'without', 'wolf', 'women', 'won', 'wonder', 'wonderful', 'wood', 'wooden', 'wool', 'word', 'wore', 'work', 'worker', 'world', 'worried', 'worry', 'worse', 'worth', 'would', 'wrapped', 'write', 'writer', 'writing', 'written', 'wrong', 'wrote', 'yard', 'year', 'yellow', 'yes', 'yesterday', 'yet', 'you', 'young', 'younger', 'your', 'yourself', 'youth', 'zero', 'zoo');

        $words = array();

        for ($i = 0; $i < $length; ++$i) $words[] = $wordlist[array_rand($wordlist)];

        return ucwords(implode(' ', $words));
    }

    /**
     * Remove any fields which can't be submitted to the database
     *
     * @param return void
     */
    private function removeInvalidAttributes()
    {
        foreach ($this->getAttributes() as $attribute => $data) if ( ! is_null($data) && ! is_scalar($data)) unset($this->$attribute);
    }

    /**
     * Save a single record
     *
     * @param array $rules
     * @param array $messages
     * @param array $data
     * @param Closure $before
     * @param Closure $after
     * @return bool
     */
    public function save(array $rules = array(), $messages = array(), $data = array(), Closure $before = null, Closure $after = null)
    {
        // Preserve whether this is a new or existing model
        $exists = $this->exists;

        if ( ! $this->useHookListeners)
        {
            // Run hooks, and abort if false is returned
            if (method_exists(get_called_class(), 'beforeCreate') && ! $exists) if ($this->beforeCreate() === false) return false;
            if (method_exists(get_called_class(), 'beforeUpdate') && $exists) if ($this->beforeUpdate() === false) return false;
            if (method_exists(get_called_class(), 'beforeSave')) if ($this->beforeSave() === false) return false;
        }

        // Add before/after save hooks passed via attributes
        if ($before) self::saving($before);
        if ($after) self::saved($after);

        // Validate record before save unless we're saving a relationship
        $valid = ($this->_validatedByVocal) ? true : $this->validate($rules, $messages, $data);

        // If record is invalid, save is unsuccessful
        if ( ! $valid) return false;

        // Hash attributes
        if (count($this->hashAttributes))
        {
            foreach ($this->attributes as $key => $value)
            {
                if (in_array($key, $this->hashAttributes) && ! is_null($value) && $value != $this->getOriginal($key)) $this->attributes[$key] = $this->hasher->make($value);
            }
        }

        // Remove fill and validation indicators if set
        if ($this->_hydratedByVocal) unset($this->_hydratedByVocal);
        if ($this->_validatedByVocal) unset($this->_validatedByVocal);

        $result = parent::save();

        // Only call hooks if we were successful
        if ( ! $result) return $result;

        if ( ! $this->useHookListeners)
        {
            if (method_exists(get_called_class(), 'afterCreate') && ! $exists) $this->afterCreate();
            if (method_exists(get_called_class(), 'afterUpdate') && $exists) $this->afterUpdate();
            if (method_exists(get_called_class(), 'afterSave')) $this->afterSave();
        }

        return $result;
    }

    /**
     * Save a record, then delete it
     *
     * @param array $rules
     * @param array $messages
     * @param array $data
     * @param Closure $before
     * @param Closure $after
     * @return bool
     */
    public function saveAndDelete($rules = array(), $messages = array(), $data = array(), Closure $before = null, Closure $after = null)
    {
        $result = $this->save($rules, $messages, $data, $before, $after);

        $this->delete();

        return $result;
    }

    /**
     * Recursively save a record
     *
     * @param array $conditions
     * @param array $rules
     * @param array $messages
     * @param array $data
     * @return bool
     */
    public function saveRecursive($conditions = array(), $rules = array(), $messages = array(), $data = array(), Closure $before = null, Closure $after = null)
    {
        // If we don't have any data passed, use input
        if ( ! count($data)) $data = Input::all();

        // Validate first
        $result = $this->validateRecursive($conditions, $rules, $messages, $data);

        if ( ! $result) return false;

        // Save this record
        $result = $this->save($rules, $messages, $data, $before, $after);

        if ( ! $result) return false;

        // See if we have any relationships to validate
        $relationships = $this->getRelationships($data, $conditions);

        // If we don't have any relationships or save failed, return errors
        if ( ! count($relationships) || ! $result) return $result;

        // Save relationships
        $result = $this->saveRelations($conditions, $rules, $messages, $relationships, $data);

        return $result;
    }

    /**
     * Attach a model instance to the parent model
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function saveRelation(Model $model)
    {
        $model->setAttribute($this->getPlainForeignKey(), $this->getParentKey());

        return $model->forceSave() ? $model : false;
    }

    /**
     * Recursively save records by relationship
     *
     * @param array $rules
     * @param array $messages
     * @param array $data
     * @param array $relationships
     * @return bool
     */
    private function saveRelations($conditions, $rules, $messages, $relationships, $data)
    {
        // Process each relationships
        foreach ($relationships as $relationship => $type)
        {
            // Capture any errors from relationships
            $relationErrors = new MessageBag;

            // Capture changes to models
            $relationDiff = array();

            // Get class/model we're working on
            $modelClass = Str::camel($relationship);
            $model = $this->$modelClass()->getModel();

            // Determine which key we will use to find an existing record
            $key = (isset($model->primaryKey)) ? $model->primaryKey : 'id';

            // Get conditions, rules and messages we will use
            list($relationConditions, $relationMessages, $relationRules) = $this->getRelationshipData($relationship, $conditions, $rules, $messages);

            if ($type == 'one')
            {
                // Find or create record
                $record = (isset($data[$relationship][$key])) ? $model->withTrashed()->find($data[$relationship][$key]) : new $model;

                // Validate
                $result = $record->validate($relationRules, $relationMessages, $data[$relationship]);

                // Save record on success, log errors on fail
                if ($result)
                {
                    if (method_exists($this->$modelClass(), 'associate')) $this->$modelClass()->associate($record)->forceSave();
                    else
                    {
                        $result = $this->$modelClass()->saveRelation($record);

                        // Attach the new record set to the parent
                        if ($result) $this->setRelation($relationship, $result);
                    }

                    // Capture record changes
                    if (count($record->diff)) $relationDiff[] = $record->diff;
                }
                else $relationErrors->merge($record->errors);
            }
            else
            {
                $records = array();

                foreach ($data[$relationship] as $index => $relationData)
                {
                    // Find or create record
                    $record = (isset($relationData[$key])) ? $model->withTrashed()->find($relationData[$key]) : new $model;

                    // Validate
                    $result = $record->validate($relationRules, $relationMessages, $relationData);

                    // Capture record on success, log errors on fail
                    if ($result) $records[] = $record;
                    else $relationErrors->add($index, $record->errors);
                }

                // Save all the records we can
                $result = $this->$modelClass()->saveMany($records);

                // If save was successful, process relationship relationships
                if (count($result))
                {
                    // Check for changes
                    foreach ($result as $index => $record) if (count($record->diff)) $relationDiff[$index] = $record->diff;

                    // Check and save any relationships
                    foreach ($data[$relationship] as $index => $relationData)
                    {
                        // If record wasn't saved, skip
                        if ( ! isset($result[$index])) continue;

                        $relationRelationships = $result[$index]->getRelationships($relationData, $relationConditions);

                        if (count($relationRelationships))
                        {
                            $relationshipResult = $result[$index]->saveRelations($relationConditions, $relationRules, $relationMessages, $relationRelationships, $relationData);

                            // Capture errors
                            if ( ! $relationshipResult) $relationErrors->add($index, $result[$index]->errors);

                            // Capture changes
                            if (count($result[$index]->diff)) $relationDiff[$index] = $result[$index]->diff;
                        }
                    }

                    // Attach the new record set to the parent
                    $this->setRelation($relationship, $this->newCollection($result));
                }
            }

            // Merge in any errors we have
            if ($relationErrors->count()) $this->errors->add($relationship, $relationErrors);

            // Capture any changes
            if (count($relationDiff)) $this->diff[$relationship] = $relationDiff;
        }

        return ($this->errors->count() == 0);
    }

    /**
     * Generate a compatible timestamp using now as default
     *
     * @param mixed $value
     * @return string
     */
    public function timestamp($value = null)
    {
        // Use now if no time was passed
        if ( ! $value) $value = $this->freshTimestamp();

        return $this->fromDateTime($value);
    }

    /**
     * Validate a single record
     *
     * @param array $rules [= array()]
     * @param array $messages [= array()]
     * @param array $data [= array()]
     * @return bool
     */
    public function validate($rules = array(), $messages = array(), $data = array())
    {
        // Call validation hook
        if ( ! $this->useHookListeners && method_exists(get_called_class(), 'beforeValidate')) if ($this->beforeValidate() === false) return false;

        // Fire validating event
        if ($this->fireModelEvent('validating') === false) return false;

        // If we have rules, use them, otherwise use rules from model
        $rules = ( ! count($rules)) ? $this->rules : $rules;

        // Remove any empty rules
        foreach ($rules as $field => $rule) if ( ! $rule) unset($rules[$field]);

        // Build any rules using ~fields and exclude current record for unique
        $rules = $this->buildValidationRules($rules);

        // Remove any fields from the model which can't be submitted, such as objects and arrays
        // - This will prevent errors with bound objects being saved twice
        $this->removeInvalidAttributes();

        // Load custom validation messages if we don't have any passed
        if ( ! count($messages)) $messages = $this->loadCustomMessages($rules);

        // We're finally ready, fill record with data if we need to
        if ($this->fillFromInput && count($this->fillable) && ! $this->_hydratedByVocal)
        {
            // If we don't have any data passed, use input
            if ( ! count($data)) $data = Input::all();

            $this->hydrateModel($data);
        }

        // Calculate difference
        $this->determineDiff();

        // If we have no rules, we're good to go!
        if ( ! count($rules)) return true;

        // Determine what we're validating
        $model = $this->getAttributes();

        // Validate
        $validator = Validator::make($model, $rules, $messages);
        $result = $validator->passes();

        // If model is valid, remove old errors
        if ($result)
        {
            $this->errors = new MessageBag;

            // Tag this model as valid
            $this->_validatedByVocal = true;
        }
        else
        {
            // Add errors messages
            $this->errors = $validator->messages();

            // Stash the input to the current session
            if (Input::hasSession()) Input::flash();
        }

        // Call after hook
        if ( ! $this->useHookListeners && method_exists(get_called_class(), 'afterValidate')) if ($this->afterValidate() === false) return false;

        $this->fireModelEvent('validated', false);

        return $result;
    }

    /**
     * Register a validated model event with the dispatcher
     *
     * @param Closure|string $callback
     * @return void
     */
    public static function validated($callback)
    {
        static::registerModelEvent('validated', $callback);
    }

    /**
     * Register a validating model event with the dispatcher
     *
     * @param Closure|string $callback
     * @return void
     */
    public static function validating($callback)
    {
        static::registerModelEvent('validating', $callback);
    }

    /**
     * Recursively validate a recordset
     *
     * @param array $conditions [= array()]
     * @param array $rules [= array()]
     * @param array $messages [= array()]
     * @param array $data [= array()]
     * @return bool
     */
    public function validateRecursive($conditions = array(), $rules = array(), $messages = array(), $data = array())
    {
        // If we don't have any data passed, use input
        if ( ! count($data)) $data = Input::all();

        // Validate this record
        $this->validate($rules, $messages, $data);

        // See if we have any relationships to validate
        $relationships = $this->getRelationships($data, $conditions);

        // If we don't have any relationships, return errors if we have them
        if ( ! count($relationships)) return ($this->errors->count() == 0);

        // Validate relationships
        $result = $this->validateRelations($conditions, $rules, $messages, $relationships, $data);

        return $result;
    }

    /**
     * Recursively save records by relationship
     *
     * @param array $conditions
     * @param array $rules
     * @param array $messages
     * @param array $data
     * @param array $relationships
     * @return bool
     */
    private function validateRelations($conditions, $rules, $messages, $relationships, $data)
    {
        // Process each relationships
        foreach ($relationships as $relationship => $type)
        {
            // Capture any errors from relationships
            $relationErrors = new MessageBag;

            // Get class/model we're working on
            $modelClass = Str::camel($relationship);
            $model = $this->$modelClass()->getModel();

            // Determine which key we will use to find an existing record
            $key = (isset($model->primaryKey)) ? $model->primaryKey : 'id';

            // Get rules and messages we will use
            list($relationConditions, $relationMessages, $relationRules) = $this->getRelationshipData($relationship, $conditions, $rules, $messages);

            if ($type == 'one')
            {
                // Find or create record
                $record = (isset($data[$relationship][$key])) ? $model->withTrashed()->find($data[$relationship][$key]) : new $model;

                // Validate and capture errors
                $result = $record->validate($relationRules, $relationMessages, $data[$relationship]);
                if ( ! $result) $relationErrors->merge($record->errors);
            }
            else
            {
                foreach ($data[$relationship] as $index => $relationData)
                {
                    // Find or create record
                    $record = (isset($relationData[$key])) ? $model->withTrashed()->find($relationData[$key]) : new $model;

                    // Validate and capture errors
                    $result = $record->validate($relationRules, $relationMessages, $relationData);
                    if ( ! $result) $relationErrors->add($index, $record->errors);

                    // Validate children if we have some
                    $relationRelationships = $record->getRelationships($relationData, $relationConditions);

                    if (count($relationRelationships))
                    {
                        $relationshipResult = $record->validateRelations($relationConditions, $relationRules, $relationMessages, $relationRelationships, $relationData);

                        if ( ! $relationshipResult) $relationErrors->add($index, $record->errors);
                    }
                }
            }

            // Merge in any errors we have
            if ($relationErrors->count()) $this->errors->add($relationship, $relationErrors);
        }

        return ($this->errors->count() == 0);
    }
}
