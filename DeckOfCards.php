<?php

$_SERVER['REQUEST_URI_PATH'] = preg_replace('/\?.*/', '', $_SERVER['REQUEST_URI']);
$segments = explode('/', trim($_SERVER['REQUEST_URI_PATH'], '/'));

$noun = '';
$context = '';
$segmentsCount = count($segments);
switch($segmentsCount) {
	case 2:
		$noun = $segments[1];
		break;
	case 3:
		$noun = $segments[1];
		$context = $segments[2];
		break;
}

function getIdFromData()
{
	$handle = fopen("php://input", "r");
	$data = fgets($handle);
	fclose($handle);
	$idArray = explode("=", $data);
	if ($idArray[0] != 'id' or !is_int((int)$idArray[1])) {
		return FALSE;
	} else {
		return $idArray[1];
	}
}

$deckOfCards = new DeckOfCards($noun);

if (!empty($noun)) {
	if ($noun == 'deck') {
		switch ($_SERVER['REQUEST_METHOD']) {
			case 'POST':
				$deckOfCards->newDeck();
				break;
			case 'GET':
				$deckOfCards->retrieveDeck($_REQUEST['id']);
				break;
			case 'PUT':
				$id = getIdFromData();
				if ($id === FALSE) {
					$deckOfCards->badRequest('Please supply a deck id');
				} else {
					if ($context == 'shuffle') {
						$deckOfCards->shuffleDeck($id);
					} else if ($context == 'cut') {
						$deckOfCards->cutDeck($id);
					} else {
						$deckOfCards->badRequest('Please supply a context, shuffle or cut');
					}
				}
				break;
			case 'DELETE':
				$id = getIdFromData();
				if ($id === FALSE) {
					$deckOfCards->badRequest('Please supply a deck id');
				} else {
					$deckOfCards->removeDeck($id);
				}
				break;
			default:
				$deckOfCards->badRequest();
		}
	} else if ($noun == 'card') {
		switch ($_SERVER['REQUEST_METHOD']) {
			case 'POST':
				if ($context == '') {
					$deckOfCards->badRequest('Please supply a card, format: value:suit, ex: 4S = 4 of spades');
				} else {
					$deckOfCards->addCard($_REQUEST['id'], $context);
				}
				break;
			case 'GET':
				$deckOfCards->getCard($_REQUEST['id'], $context);
				break;
			case 'DELETE':
				$id = getIdFromData();
				if ($id === FALSE) {
					$deckOfCards->badRequest('Please supply a deck id');
				} else {
					$deckOfCards->removeCard($id, $context);
				}
				break;
			default:
				$deckOfCards->badRequest();
		}
	} else {
		$deckOfCards->resourceNotFound();
	}

} else {
	$deckOfCards->resourceNotDefined();
}

class DeckOfCards
{
	private $noun;
	private $mysqli;

	private $errorCode;
	private $response;

	private $suits;
	private $values;
	private $jokers;

	public function __construct($noun)
	{
		self::setDBConnection();

		$this->noun = $noun;

		$this->suits = array('S', 'D', 'C', 'H');
		$this->values = array('2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A');
		$this->jokers = array('lJ', 'bJ'); //little joker and big joker
	}

	public function __destruct()
	{
		$this->mysqli->close();
	}

	///////////////////////////////////////////////////////////////////////////////
	////////////////////////////// DECK CALLS /////////////////////////////////////
	///////////////////////////////////////////////////////////////////////////////

	public function newDeck()
	{
		$deck = self::createDeck();
		$id = self::saveDeck($deck);
		if ($id != FALSE) {
			$data = array("id" => $id, "deck" => $deck);
			$this->echoResponse('none', array(), '', 'success', $data);
		} else {
			$this->internalServerError();
		}
	}

	public function retrieveDeck($dirtyId)
	{
		$cleanId = $this->mysqli->real_escape_string($dirtyId);
		$deck = self::getDeck($cleanId);
		if ($deck === FALSE) {
			$this->resourceNotFound();
		} else {
			$data = array("id" => $cleanId, "deck" => $deck);
			$this->echoResponse('none', array(), '', 'success', $data);
		}
	}

	public function shuffleDeck($dirtyId)
	{
		$cleanId = $this->mysqli->real_escape_string($dirtyId);
		$deck = self::getDeck($cleanId);
		if ($deck === FALSE) {
			$this->internalServerError();
		} else {
			shuffle($deck);
			if (self::updateDeck($cleanId, $deck) === FALSE) {
				$this->internalServerError();
			} else {
				$data = array("id" => $cleanId, "deck" => $deck);
				$this->echoResponse('none', array(), '', 'success', $data);
			}
		}
	}

	public function cutDeck($dirtyId)
	{
		$cleanId = $this->mysqli->real_escape_string($dirtyId);
		$deck = self::getDeck($cleanId);
		if ($deck === FALSE) {
			$this->internalServerError();
		} else {
			$cutDepth = count($deck) / 2;
			$deckBottom = array_splice($deck, $cutDepth);
			$cutDeck = array_merge($deckBottom, $deck);
			if (self::updateDeck($cleanId, $cutDeck) === FALSE) {
				$this->internalServerError();
			} else {
				$data = array("id" => $cleanId, "deck" => $cutDeck);
				$this->echoResponse('none', array(), '', 'success', $data);
			}
		}
	}

	public function removeDeck($dirtyId)
	{
		$cleanId = $this->mysqli->real_escape_string($dirtyId);

		if (self::deleteDeck($cleanId) === FALSE) {
			$this->internalServerError();
		} else {
			$this->echoResponse('none', array(), '', 'success', array());
		}
	}

	///////////////////////////////////////////////////////////////////////////////
	////////////////////////////// CARD CALLS /////////////////////////////////////
	///////////////////////////////////////////////////////////////////////////////

	public function addCard($dirtyId, $card)
	{
		$cleanId = $this->mysqli->real_escape_string($dirtyId);
		$deck = self::getDeck($cleanId);
		if ($deck === FALSE) {
			$this->internalServerError();
		} else {
			array_unshift($deck, $card);
			if (self::updateDeck($cleanId, $deck) === FALSE) {
				$this->internalServerError();
			} else {
				$data = array("id" => $cleanId, "deck" => $deck);
				$this->echoResponse('none', array(), '', 'success', $data);
			}
		}
	}

	public function getCard($dirtyId, $context)
	{
		$cleanId = $this->mysqli->real_escape_string($dirtyId);
		$deck = self::getDeck($cleanId);
		if ($deck === FALSE) {
			$this->resourceNotFound();
		} else {
			$card = '';
			switch($context) {
				case 'top':
					$card = $deck[0];
					break;
				case 'bottom':
					$card = $deck[count($deck) - 1];
					break;
				case 'random':
					$key = array_rand($deck, 1);
					$card = $deck[$key];
					break;
				default:
					$key = array_search($context, $deck);
					if ($key === FALSE) {
						$this->resourceNotFound();
					} else {
						$card = $context;
					}
					break;
			}
			$data = array("id" => $cleanId, "card" => array($card));
			$this->echoResponse('none', array(), '', 'success', $data);
		}
	}

	public function removeCard($dirtyId, $card)
	{
		$cleanId = $this->mysqli->real_escape_string($dirtyId);
		$deck = self::getDeck($cleanId);
		if ($deck === FALSE) {
			$this->internalServerError();
		} else {
			$key = array_search($card, $deck);
			if ($key === FALSE) {
				$this->resourceNotFound();
			} else {
				unset($deck[$key]);
				$newDeck = array_values($deck);
				if (self::updateDeck($cleanId, $newDeck) === FALSE) {
					$this->internalServerError();
				} else {
					$data = array("id" => $cleanId, "deck" => $newDeck);
					$this->echoResponse('none', array(), '', 'success', $data);
				}
			}
		}
	}

	///////////////////////////////////////////////////////////////////////////////
	////////////////////////////// DECK CRUD FUNCTIONS ////////////////////////////
	///////////////////////////////////////////////////////////////////////////////

	private function createDeck()
	{
		$deck = array();
		foreach ($this->suits as $suit) {
			foreach ($this->values as $value) {
				$deck[] = $value . $suit;
			}
		}
		$deck[] = $this->jokers[0];
		$deck[] = $this->jokers[1];

		return $deck;
	}

	private function getDeck($id)
	{
		$sql = "
			SELECT deck
			FROM decks
			WHERE id = '$id'
		";

		$result = $this->mysqli->query($sql);
		if ($result === FALSE) return FALSE;

		$row = mysqli_fetch_assoc($result);
		if ($row === null) return FALSE;

		$encodedDeck = $row['deck'];
		$deck = json_decode($encodedDeck);

		$result->close();

		return $deck;
	}

	private function saveDeck($deck)
	{
		$encodedDeck = json_encode($deck);

		$sql = "
			INSERT INTO decks
			SET	deck = '$encodedDeck'
		";

		$this->mysqli->query($sql);

		if ($this->mysqli->affected_rows == 1) {
			return $this->mysqli->insert_id;
		} else {
			return FALSE;
		}
	}

	private function updateDeck($id, $deck)
	{
		$encodedDeck = json_encode($deck);

		$sql = "
			UPDATE decks
			SET	deck = '$encodedDeck'
			WHERE id = '$id'
		";

		$this->mysqli->query($sql);

		if ($this->mysqli->affected_rows == 1) {
			return TRUE;
		} else {
			return FALSE;
		}
	}

	private function deleteDeck($id)
	{
		$sql = "
			DELETE FROM decks
			WHERE id = '$id'
		";

		$this->mysqli->query($sql);

		if ($this->mysqli->affected_rows == 1) {
			return TRUE;
		} else {
			return FALSE;
		}
	}

	///////////////////////////////////////////////////////////////////////////////
	////////////////////////////// RESPONSE FUNCTIONS /////////////////////////////
	///////////////////////////////////////////////////////////////////////////////

	public function badRequest($error = null)
	{
		http_response_code(400);
		$errorCode = 'badRequest';
		$friendlyError = ($error !== null) ? $error : 'Bad Request';
		$errors = array($friendlyError);
		$this->echoResponse($errorCode, $errors, $friendlyError, 'fail', (object)array());
	}

	public function resourceNotFound()
	{
		http_response_code(404);
		$errorCode = 'resourceNotFound';
		$friendlyError = 'Resource Not Found';
		$errors = array($friendlyError);
		$this->echoResponse($errorCode, $errors, $friendlyError, 'fail', (object)array());
	}

	public function resourceNotDefined()
	{
		http_response_code(400);
		$errorCode = 'resourceNotDefined';
		$friendlyError = 'Resource Not Defined';
		$errors = array($friendlyError);
		$this->echoResponse($errorCode, $errors, $friendlyError, 'fail', (object)array());
	}

	public function internalServerError()
	{
		http_response_code(500);
		$errorCode = 'internalServerError';
		$friendlyError = 'Internal Server Error';
		$errors = array($friendlyError);
		$this->echoResponse($errorCode, $errors, $friendlyError, 'fail', (object)array());
	}

	///////////////////////////////////////////////////////////////////////////////
	////////////////////////////// MISC FUNCTIONS /////////////////////////////////
	///////////////////////////////////////////////////////////////////////////////

	private function setDBConnection()
	{
		$this->mysqli = new mysqli('localhost', 'deckOfCards', self::getCreds(), 'deckOfCards');
	}

	private function getCreds()
	{
		return trim(file_get_contents('../../creds/deckOfCards', FILE_USE_INCLUDE_PATH));
	}

	///////////////////////////////////////////////////////////////////////////////
	////////////////////////////// CLOSING FUNCTION ///////////////////////////////
	///////////////////////////////////////////////////////////////////////////////

	private function echoResponse($errorCode, $errors, $friendlyErrors, $result, $data)
	{
		$this->errorCode = $errorCode;

		$jsonResponse = array();
		$jsonResponse['httpStatus'] = http_response_code();
		$jsonResponse['noun'] = $this->noun;
		$jsonResponse['verb'] = $_SERVER['REQUEST_METHOD'];
		$jsonResponse['errorCode'] = $errorCode;
		$jsonResponse['errors'] = $errors;
		$jsonResponse['friendlyError'] = $friendlyErrors;
		$jsonResponse['result'] = $result;
		$jsonResponse['data'] = $data;
		$this->response = json_encode($jsonResponse);
		header('Content-type: application/json');
		echo $this->response;
	}
}