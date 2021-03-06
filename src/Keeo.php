<?php

namespace FOSOpenScouting\Keeo;

use DateTime;
use FOSOpenScouting\Keeo\Entity\Event;
use FOSOpenScouting\Keeo\Entity\EventCategory;
use FOSOpenScouting\Keeo\Entity\Person;
use FOSOpenScouting\Keeo\Entity\PersonFunction;
use FOSOpenScouting\Keeo\Entity\PriceCategory;
use FOSOpenScouting\Keeo\Entity\Unit;
use FOSOpenScouting\Keeo\Exception\BadRequestException;
use FOSOpenScouting\Keeo\Exception\ConflictAtEventSubscriptionException;
use FOSOpenScouting\Keeo\Exception\CredentialsDoNotMatchException;
use FOSOpenScouting\Keeo\Exception\ForbiddenEventSubscriptionException;
use FOSOpenScouting\Keeo\Exception\InvalidResponseException;
use FOSOpenScouting\Keeo\Exception\PersonAlreadySubscribedToEventException;
use FOSOpenScouting\Keeo\KeeoConnector;
use InvalidArgumentException;

class Keeo
{
    /**
     * @var KeeoConnector
     */
    protected $keeoConnector;
    /**
     * @var Config
     */
    protected $config;

    /**
     * @param Config|array $config
     */
    public function __construct($config) {
        $this->config = $config instanceof Config ? $config : new Config($config);
        $this->keeoConnector = new KeeoConnector($this->config);
    }
    
    /**
     * Checks the given credentials against keeo. Returns true if the credentials are correct.
     * Throws an exception when the login failed.
     *
     * @param $stemnumber
     * @param $password
     * @return bool
     * @throws InvalidResponseException
     * @throws CredentialsDoNotMatchException
     */
    public function userLogin ($stemnumber, $password) {
        $credentialsCorrect = false;

        $response = $this->keeoConnector->post('/person/login.json', array(
            'login' => $stemnumber,
            'password' => $password
        ));

        // validate response
        if(!empty($response->headers['X-Json'])){
            // remove the ( and ) at the beginning en ending of this string
            $json = substr($response->headers['X-Json'], 1, -1);
            $receivedData = json_decode($json, true);

            if(isset($receivedData['result']) && $receivedData['result'] = 'ok' && isset($receivedData['authenticated'])) {
                if($receivedData['authenticated']) {
                    if(!isset($receivedData['hash'])) throw new InvalidResponseException();
                    // check the hash
                    $receivedHash = $receivedData['hash'];
                    $calculatedHash = md5($stemnumber.$this->config->getUserLoginSalt().$password.date('YmdH'));

                    if($receivedHash == $calculatedHash) {
                        $credentialsCorrect = true;
                    }
                } else {
                    $message = '';
                    if(isset($receivedData['message'])) {
                        $message = $receivedData['message'];
                    }
                    throw new CredentialsDoNotMatchException($message);
                }
            } else {
                throw new InvalidResponseException();
            }
        }

        return $credentialsCorrect;
    }

    /**
     * Get a person from Keeo
     *
     * @param $stemnumber
     * @return Person
     * @throws InvalidResponseException
     */
    public function getPerson($stemnumber)
    {
        $result = null;

        $response = $this->keeoConnector->get('/person/'.$stemnumber.'.json');

        switch ($response->headers['Status-Code']) {
            case '200':
                $personData = json_decode($response->body, true);
                if(isset($personData['person'])) {
                    $personData = $personData['person'];
                } else {
                    throw new InvalidResponseException();
                }
                $result = new Person($personData);
                break;
            case '404': // person not found
            case '410': // person deleted
            default:
                $result = null;
        }

        return $result;
    }

    /**
     * Get a function from Keeo
     *
     * @return PersonFunction
     */
    public function getFunctions()
    {
        $functions = array();

        $response = $this->keeoConnector->get('/person/functions.json');
        $functionsData = json_decode($response->body, true);

        if(isset($functionsData['functions'])) $functionsData = $functionsData['functions'];
        else throw new InvalidResponseException();

        foreach($functionsData as $functionData) {
            $functions[] = new PersonFunction($functionData);
        }

        return $functions;
    }

    /**
     * Checks if a user exists with the given attributes
     *
     * @param string $firstName
     * @param string $name
     * @param string $email
     * @param DateTime $birthDate
     * @return array
     * @throws InvalidArgumentException
     */
    public function findUser($firstName = '', $name = '', $email = '', DateTime $birthDate = null)
    {
        $foundUsers = array();

        // build search params
        $searchParams = array();
        if(!empty($firstName)){
            $searchParams['first_name'] = $firstName;
        }
        if(!empty($name)){
            $searchParams['name'] = $name;
        }
        if(!empty($email)){
            $searchParams['email'] = $email;
        }
        if(!empty($birthDate)){
            $searchParams['birth_date'] = $birthDate->format('Y-m-d');
        }

        if(!empty($searchParams)) {
            $response = $this->keeoConnector->post('/person/verify.json', $searchParams);

            if($response->headers['Status-Code'] == '200') {
                $foundUsers = json_decode($response->body, true);
            }
        } else {
            throw new InvalidArgumentException('At least one search parameter needs to be given.');
        }

        return $foundUsers;
    }

    /**
     * Get a unit from Keeo
     *
     * @param $unitNumber
     * @return Unit
     */
    public function getUnit($unitNumber)
    {
        $response = $this->keeoConnector->get('/unit/'.$unitNumber.'.json');
        $unitData = json_decode($response->body, true);

        if(isset($unitData['unit_data'])) $unitData = $unitData['unit_data'];
        else throw new InvalidResponseException();

        return new Unit($unitData);
    }

    /**
     * Get the number of persons in a given unit
     *
     * @param Unit|string|int $unit Unit object or unit number
     * @param PersonFunction|string|int|null $function
     * @return int
     * @throws InvalidArgumentException
     */
    public function getNumberOfPersonsInUnit($unit, $function = null)
    {
        if (is_numeric($unit)) {
            $unitNumber = $unit;
        } elseif ($unit instanceof Unit) {
            $unitNumber = $unit->getNumber();
        } else {
            throw new InvalidArgumentException('Unit must be an instance of Unit or be an numerical value');
        }

        $params = array(
            'number' => $unitNumber
        );

        if(!empty($function)) {
            if (is_numeric($function)) {
                $params['function_number'] = $function;
            } elseif ($function instanceof PersonFunction) {
                $params['function_number'] = $function->getNumber();
            } else {
                throw new InvalidArgumentException('Function must be an instance of PersonFunction or be an numerical value');
            }
        }

        $response = $this->keeoConnector->post('/unit/search-member-count.json', $params);
        $responseBody = json_decode($response->body, true);

        if(isset($responseBody['count'])) {
            $numberOfPersons = (int) $responseBody['count'];
        } else {
            throw new InvalidResponseException("Expected key 'count' not found in the response body");
        }

        return $numberOfPersons;
    }

    /**
     * Get all members in a unit
     *
     * @param $unitNumber
     * @return Person[]
     */
    public function getAllMembersInUnit($unitNumber)
    {
        return $this->searchMembersInUnit($unitNumber);
    }

    /**
     * @param $unitNumber
     * @param string $functionNumber
     * @return Person[]
     * @throws InvalidResponseException
     */
    public function searchMembersInUnit($unitNumber, $functionNumber = null)
    {
        $members = array();

        $params = array(
            'number' => $unitNumber
        );
        if(!empty($functionNumber)) $params['function_number'] = $functionNumber;

        $response = $this->keeoConnector->get('/unit/search-members.json', $params);
        $membersData = json_decode($response->body, true);

        if(isset($membersData['unit_members'])) {
            $membersData = $membersData['unit_members'];
        } else {
            throw new InvalidResponseException();
        }

        foreach($membersData as $memberData) {
            $members[] = new Person($memberData);
        }

        return $members;
    }

    /**
     * Get all unit categories
     *
     * @return array (
     *      'id' => id,
     *      'name" => name,
     *      'children' => array(
     *          ...
     *      )
     * )
     */
    public function getUnitCategories()
    {
        $response = $this->keeoConnector->get('/unit/categories.json');
        $categories = json_decode($response->body, true);

        if(isset($categories['structure'])) {
            $categories = $categories['structure'];
        } else {
            throw new InvalidResponseException();
        }

        return $categories;
    }

    /**
     * Get all unitnumbers in Keeo
     *
     * @return string[]
     */
    public function getAllUnitNumbers()
    {
        return $this->getUnitsNumbersInCategory(1);
    }

    /**
     * Get all unit numbers within a category
     *
     * @param $categoryId
     * @return string[]
     * @throws InvalidResponseException
     */
    public function getUnitsNumbersInCategory($categoryId)
    {
        $params = array();
        if(!empty($categoryId)) $params['category_id'] = $categoryId;

        $response = $this->keeoConnector->get('/unit/select-by-category-id.json', array(
            'category_id' => $categoryId
        ));
        $unitData = json_decode($response->body, true);

        if(isset($unitData['unit_numbers'])) {
            $unitNumbers = $unitData['unit_numbers'];
        } else {
            throw new InvalidResponseException();
        }

        return $unitNumbers;
    }


    /**
     * Gets the event categories
     *
     * @return EventCategory[]
     * @throws InvalidResponseException
     */
    public function getEventCategories()
    {
        $categories = array();

        $response = $this->keeoConnector->get('/event/categories.json');
        $categoriesData = json_decode($response->body, true);

        if(isset($categoriesData['event_categories'])) {
            $categoriesData = $categoriesData['event_categories'];
        } else {
            throw new InvalidResponseException();
        }

        foreach($categoriesData as $categoryData) {
            $categories[] = new EventCategory($categoryData);
        }

        return $categories;
    }

    /**
     * Searches events based on given parameters
     *
     * @param int|null $categoryId   category id, found in getEventCategories()
     * @param DateTime|null $startDateFrom   start date later or equal to this value
     * @param DateTime|null $startDateUntil  start date earlier or equal to this value
     * @param DateTime|null $endDateFrom     end date later or equal to this value
     * @param DateTime|null $endDateUntil    end date earlier or equal to this value
     *
     * @return string[]     Event codes
     * @throws InvalidArgumentException
     */
    public function findEvents(
        $categoryId = null,
        DateTime $startDateFrom = null,
        DateTime $startDateUntil = null,
        DateTime $endDateFrom = null,
        DateTime $endDateUntil = null
    ) {
        // build search params
        $searchParams = array();
        if(!empty($categoryId)){
            $searchParams['category_id'] = $categoryId;
        }
        if(!empty($startDateFrom)){
            $searchParams['start[from]'] = $startDateFrom->format('Y-m-d');
        }
        if(!empty($startDateUntil)){
            $searchParams['start[until]'] = $startDateUntil->format('Y-m-d');
        }
        if(!empty($endDateFrom)){
            $searchParams['end[from]'] = $endDateFrom->format('Y-m-d');
        }
        if(!empty($endDateUntil)){
            $searchParams['end[until]'] = $endDateUntil->format('Y-m-d');
        }

        $foundEvents = array();
        if(!empty($searchParams)) {
            $response = $this->keeoConnector->post('/event/search.json', $searchParams);

            if($response->headers['Status-Code'] == '200') {
                $foundEvents = json_decode($response->body, true);
                $foundEvents = $foundEvents['event_codes'];
            }
        } else {
            throw new InvalidArgumentException('At least one search parameter needs to be given.');
        }

        return $foundEvents;
    }

    /**
     * Get the event with the given event code
     *
     * @param $eventCode
     * @return Event
     * @throws InvalidResponseException
     */
    public function getEvent($eventCode)
    {
        $response = $this->keeoConnector->get('/event/' . $eventCode . '.json');
        $eventData = json_decode($response->body, true);

        if(isset($eventData['event'])) $eventData = $eventData['event'];
        else throw new InvalidResponseException();

        return new Event($eventData);
    }

    public function subscribePersonToEvent(
        $person,
        $event,
        $administrator,
        $administratorPassword,
        $priceCategory = null
    ) {
        $subscribeParams =array();

        if ($person instanceof Person) {
            $person = $person->getStemnumber();
        }
        $subscribeParams['person_code'] = $person;

        if ($event instanceof Event) {
            $event = $event->getCode();
        }
        $subscribeParams['event_code'] = $event;

        if ($administrator instanceof Person) {
            $administrator = $administrator->getStemnumber();
        }
        $subscribeParams['administrator_code'] = $administrator;
        $subscribeParams['administrator_password'] = $administratorPassword;
        if (!is_null($priceCategory)) {
            if ($priceCategory instanceof PriceCategory) {
                $priceCategory = $priceCategory->getId();
            }
            $subscribeParams['price_category_id'] = $priceCategory;
        }

        try {
            $response = $this->keeoConnector->post('/event/subscribe.json', $subscribeParams);
        } catch (BadRequestException $e) {
            if ($e->getMessage() == 'This person is already subscribed to this event.') {
                throw new PersonAlreadySubscribedToEventException($e->getMessage());
            } else {
                throw $e;
            }
        }

        switch ($response->headers['Status-Code']) {
            case '204': // No content
                // Person successfully subscribed to event
                break;
            case '403': // Forbidden
                // admin password is not correct
                throw new ForbiddenEventSubscriptionException(KeeoConnector::extractErrorMessageFromResponseHeaders($response->headers));
                break;
            case '409': // Conflict
                // Wrong input
                throw new ConflictAtEventSubscriptionException(KeeoConnector::extractErrorMessageFromResponseHeaders($response->headers));
                break;
            default:
                break;
        }
    }
}
