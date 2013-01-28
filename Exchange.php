<?php

namespace h4kuna;

use Nette,
    Nette\Http\SessionSection,
    Nette\Http\Request;

/**
 * PHP > 5.3
 *
 * @author Milan Matějček
 * @since 2009-06-22 - version 0.5
 * @version 3.3
 * @property-read $default
 * @property $date
 * @property $vat
 */
class Exchange extends \ArrayIterator implements IExchange {

    /**
     * number of version
     * @var string
     */
    private static $version = FALSE;

    /** @var Nette\Utils\Html */
    private static $href;

    /**
     * param in url for change value
     */

    const PARAM_CURRENCY = 'currency';
    const PARAM_VAT = 'vat';

//-----------------config section-----------------------------------------------
    /**
     * default money on web, must be UPPER
     * @var string
     */
    private $default;

    /**
     * show actual money for web and is first in array, must be UPPER
     * @var string
     */
    private $web;

//------------------------------------------------------------------------------

    /**
     * last working value
     * @var array
     */
    protected $lastChange = array(NULL, NULL);

    /** @var DateTime */
    private $date;

    /** @var Storage */
    private $storage;

    /** @var Download */
    private $download;

    /** @var Money */
    private $number;

    /** @var SessionSection */
    private $session;

    /** @var Request */
    private $request;

    public function __construct(Storage $storage, Request $request, SessionSection $session, Money $number = NULL, Download $download = NULL) {
        parent::__construct();
        $this->storage = $storage;
        $this->download = $download ? $download : new CnbDay;
        if ($this->number) {
            $this->number = $number;
        } else {
            $this->number = new Money();
            $this->setVat();
        }
        $this->session = $session;
        $this->request = $request;
    }

    /**
     * return property of currency
     * @example usdProfil
     * @param string $name
     * @param strong $args
     * @return mixed
     */
    public function __get($name) {
        $code = $this->loadCurrency(substr($name, 0, 3));
        $name = strtolower(substr($name, 3));
        return ($name) ? $this[$code][$name] : $this[$code];
    }

    /** set date for download */
    public function setDate(\DateTime $date = NULL) {
//        $date = ($date instanceof \DateTime || !$date) ? $date : new \DateTime($date);
//        if ($date == $this->date) {
//            return;
//        }
//        $this->date = $date;
//        $this->download($this->date);
//        $store = $this->getStorage();
//        foreach ($this as $k => $v) {
//            $data = (array) $store[$k];
//            $data['profil'] = $v['profil'];
//            $this->offsetSet($k, $data);
//        }
    }

    /**
     * 1.2 or 20 or 0.2
     * @param number $vat
     * @param bool $in
     * @param bool $out
     * @return \h4kuna\Exchange
     */
    public function setVat($vat = 21, $in = TRUE, $out = TRUE) {
        $this->number->setVat($vat)->setVatIO($in, $out);
        return $this;
    }

//-----------------methods for template
    /**
     * @param string $code
     * @param bool $symbol
     * @return Nette\Utils\Html
     */
    public function currencyLink($code, $symbol = TRUE) {
        $code = $this->loadCurrency($code);
        $a = self::getHref();
        $a->setText(($symbol) ? $this[$code]['profil']->symbol : $code);

        if ($this->web === $code) {
            $a->class = 'current';
        }
        return $a->href(NULL, array(self::PARAM_CURRENCY => $code));
    }

    /**
     * create link for vat
     * @param string $textOn
     * @param string $textOff
     * @return Nette\Utils\Html
     */
    public function vatLink($textOn, $textOff) {
        $a = self::getHref();
        $isVatOn = $this->number->isVatOn();
        $a->href(NULL, array(self::PARAM_VAT => !$isVatOn));
        if ($isVatOn) {
            $a->setText($textOff);
        } else {
            $a->setText($textOn);
        }
        return $a;
    }

    /**
     * array for form to addSelect
     * @param string $key
     * @return array
     */
    public function selectInput($key = self::SYMBOL) {
        $out = array();
        foreach ($this as $k => $v) {
            $out[$k] = isset($v[$key]) ? $v[$key] : $k;
        }
        return $out;
    }

    /**
     * create helper to template
     */
    public function registerAsHelper(Nette\Templating\Template $tpl) {
        $tpl->registerHelper('formatVat', callback($this, 'formatVat'));
        $tpl->registerHelper('currency', callback($this, 'format'));
        $tpl->exchange = $this;
    }

    /**
     * transfer number by exchange rate
     * @param double|int|string $price number
     * @param string $from default currency
     * @param string $to output currency
     * @param int $round number round
     * @return double
     */
    public function change($price, $from = NULL, $to = NULL, $round = NULL) {
        return $this->_change($price, $from, $to, $round);
    }

    /**
     * transfer number by exchange rate
     * @param double|int|string $price number
     * @param string $from default currency
     * @param string $to output currency
     * @param int $round number round
     * @return double
     */
    public function _change($price, &$from = NULL, &$to = NULL, $round = NULL) {
        $_price = new Float($price);

        $from = (!$from) ? $this->getDefault() : $this->loadCurrency($from);
        $to = (!$to) ? $this->getWeb() : $this->loadCurrency($to);
        $price = $this[$to][self::RATE] / $this[$from][self::RATE] * $_price->getValue();

        if ($round !== NULL) {
            $price = round($price, $round);
        }

        return $price;
    }

    /**
     * count, format price and set vat
     * @param number $number price
     * @param string|bool $from FALSE currency doesn't counting, NULL set actual
     * @param string $to output currency, NULL set actual
     * @param bool|real $vat use vat, but get vat by method $this->formatVat(), look at to globatVat upper
     * @return number string
     */
    public function format($number, $from = NULL, $to = NULL, $vat = NULL) {
        $old = $this->getWeb();
        if ($to) {
            $this->web = $to = $this->loadCurrency($to);
        }

        if ($from !== FALSE) {
            $number = $this->_change($number, $from, $to);
        }

        $vat = $this->lastChange[0] = ($vat === NULL) ? $this->number->getVat() : Vat::create($vat);
        $out = $this->lastChange[1] = $this[$to]['profil'];

        $out->setNumber($number);

        if ($to !== FALSE) {
            $this->web = $old;
        } else {
            $to = $this->getWeb();
        }

        return $this->number->render($out, $vat);
    }

    /**
     * before call this method MUST call method format()
     * formating price only with vat
     * @return string
     */
    public function formatVat() {
        return $this->number->withVat($this->lastChange[1], $this->lastChange[0]);
    }

    /**
     * load currency by code
     * @param string $code
     * @return string
     */
    public function loadCurrency($code, $property = array()) {
        $code = strtoupper($code);

        if (!$this->offsetExists($code)) {
            if (!$this->default) {
                $this->default = $code;
                $this->init();
            }
            $this->offsetSet($code, $this->storage[$code]);
        }

        if ($property || !isset($this[$code]['profil'])) {
            if (!$property) {
                $profil = $this->getDefaultProfile();
                $profil->setSymbol($code);
            } elseif (is_array($property)) {
                $profil = $this->getDefaultProfile();
                foreach ($property as $k => $v) {
                    $profil->{$k} = $v;
                }
            } elseif ($property instanceof NumberFormat) {
                $profil = $property;
            }

            $this[$code]['profil'] = $profil;
        }

        return $code;
    }

// <editor-fold defaultstate="collapsed" desc="getter">
    /**
     * @return Exchange
     */
    public function loadAll() {
        $code = $this->getDefault();
        do {
            $this->loadCurrency($code);
            $code = $this[$code]['next'];
        } while ($code);
        return $this;
    }

    /** @return string */
    public function getWeb() {
        if ($this->web === NULL) {
            return $this->getDefault();
        }
        return $this->web;
    }

    public function getDefault() {
        if ($this->default === NULL) {
            return $this->loadCurrency(self::CZK);
        }
        return $this->default;
    }

    public function getDate() {
        if (!$this->date) {
            $this->setDate();
        }
        return $this->date;
    }

    /**
     * version of this class
     * @return string
     */
    static public function getVersion() {
        if (self::$version === FALSE) {
            $rc = new \ReflectionClass(__CLASS__);
            preg_match('~@version (.*)~', $rc->getDocComment(), $array);
            self::$version = $array[1];
        }
        return self::$version;
    }

// </editor-fold>
//-----------------protected
    protected function init() {
        $this->download->setDefault($this->getDefault());
        $this->download();
        $this->session();
    }

    /**
     * start download the source
     * @return void
     */
    protected function download() {
        if (!isset($this->storage[$this->getDefault()])) {
            $this->storage->import($this->download->downloading(), $this->getDefault());
        }
    }

    /**
     * setup session
     */
    protected function session() {
//-------------crrency
        $qVal = strtoupper($this->request->getQuery(self::PARAM_CURRENCY));
        if (!isset($this->storage[$qVal])) {
            $qVal = NULL;
        }

        if ($qVal) {
            $this->session->currency = $qVal;
        } elseif (!isset($this->session->currency)) {
            $this->session->currency = is_null($this->web) ? $this->getDefault() : $this->getWeb();
        }
        $this->web = $this->session->currency;

//-------------vat
        $qVal = $this->request->getQuery(self::PARAM_VAT);
        if ($qVal !== NULL) {
            $this->session->vat = (bool) $qVal;
            if ($qVal) {
                $this->number->vatOn();
            } else {
                $this->number->vatOff();
            }
        } elseif (!isset($this->session->vat)) {
            $this->session->vat = $this->number->isVatOn();
        }
    }

    /** @return Nette\Utils\Html */
    protected static function getHref() {
        if (!self::$href)
            self::$href = Nette\Utils\Html::el('a');
        return clone self::$href;
    }

    protected function getDefaultProfile() {
        return clone $this->number;
    }

}
