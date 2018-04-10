<?php
/**
 * Experimental parser inspired by parsec 
 */

function isEmpty($a)
{
    if(is_array($a) && count($a) == 0) {
        return true;
    }
    return false;
}

function to_ary(...$args)
{
    return $args;
}
/** 
 * array to string
 */
function to_s($s,$args)
{
    foreach($args as $arg){
        $s = is_array($arg) ? to_s($s, $arg) : $s. $arg;
    }
    return $s;
}

/**
 * basic parsers: 'return',failure and item
 */

/**
 * always returns 
 */
function _return($s)
{
    return function ($inp) use ($s) {
        return [[$s,$inp]];
    };
}

/**
 * always returns failure condition 
 */
function failure()
{
    return function ($inp) {
        return [];
    };
}
/**
 * returns single char in head and and rest of input in tail as list 
 */
function item()
{
    $f =  function ($inp) {
        if($inp == '') {
            return [];
        }else{
            return [[$inp[0], substr($inp, 1)]];
        }
    };
    
    return $f;
}

/**
 * parse input with parser 
 */
function parse($p,$inp)
{
    return $p($inp);
}            

/**
 * Sequence parsers
 * if first parser succeeds
 * then apply the second
 * otherwise fail
 */
function then($p, $fp)
{
    $f = function ($inp) use ($p,$fp) {
        $res = parse($p, $inp);
        if(isEmpty($res)) {
            return [];
        }else{
            list($v,$out) = $res[0];
            $p2 = $fp($v);
            return parse($p2, $out);
        }
    };
    return $f;
}
/**
 * Sequence parsers, then combine results
 * into one value by applying $fin
 * 
 * each parser gets remaining input from
 * previous successful parser, while each 
 * parser's results are collected and finally
 * applied to a function at the end
 */
function seq($final,...$ps)
{
    $acc = [];//results
    $f = null;//parser var
    /**
 * collect results then call final function 
*/
    $call_final = function ($acc) use ($final) {
        return call_user_func_array($final, $acc);
    };
    /**
 * build sequence of nested parsers 
*/
    for($i = count($ps) - 1; $i>=0;$i--){    
        $f = then(
            $ps[$i], function ($x) use (&$acc,$f,$call_final) {        
                $acc[] = $x;
                return $f? $f: _return($call_final($acc));
            }
        );
    }
    /**
 * wrap parser to reset results 
*/
    return function ($inp) use ($f,&$acc) {
        $res = parse($f, $inp);
        $acc = [];
        return $res;
    };
}


/**
 * treat the results of several
 * parsers as strings and join
 * their results
 */
function seq_s(...$funcs)
{
    $to_s = function (...$args) {
        return  to_s('', $args);
    }; 
    return seq($to_s, ...$funcs);
}

/**
 * Choice
 *
 * apply first parser then apply 
 * second parser if first failed
 */
function orElse($p, $p2)
{
    return function ($inp) use ($p,$p2) {
        $res = parse($p, $inp);
        if(isEmpty($res)) {
             return parse($p2, $inp);
        }else{
             return $res;
        }
    };
}


/**
 * apply parser 0 or more times
 */
function many($p)
{
    return orElse(many1($p), _return([]));
}


/**
 * apply parser 1 or more times
 */
function many1($p)
{
    return function ($s) use ($p) {
        $ac = [] ;
        $res = parse($p, $s);
        if(isEmpty($res)) {
            return [];
        }
        do {
            list($ht,$s) = $res[0];
            $ac[] = $ht;
            $res = parse($p, $s);
        } while (!isEmpty($res));
        //$ret = $join? join('', $ac): $ac;//revisit this
        return [[$ac,$s]];
    };
}

/**
 * applies predicate to input
 * if true return input
 * otherwise fail
 */
function sat($pred)
{
    return then(
        item(), function ($i1) use ($pred) {
            if($pred($i1)) {
                return _return($i1);
            }else{
                return failure();
            }
        }
    );
}

/**
 * predicate function from 
 * regex
 */
function match($rgx)
{
    return function ($in) use ($rgx) {
        return preg_match($rgx, $in);
    };
}

/**
 * parser for lower case char 
 */
function lower()
{
    return sat(match('/[a-z]/'));
}
/**
 * parser for digit 
 */
function digit()
{
    return sat(match('/\d/'));

}
/**
 * single alphanumeric char 
 */
function alphanum()
{
    return sat(match('/\w|_/'));
}
/**
 * space 
 */
function isSpace()
{
    return sat(match('/\s/'));
}
/**
 * string of digits 
 */
function nat()
{
    return seq_s(many1(digit()));
}

/**
 * string of spaces 
 */
function space()
{
    return then(
        many(isSpace()), function ($spc) {
            return _return('');
        }
    );
}

/**
 * successful parse
 * surrounded by space
 */
function token($p)
{
    return seq_s(space(), $p, space());
}

/**
 * char string that begins with lower case char 
 */
function ident()
{
    return  seq_s(lower(), many(alphanum()));
}

/**
 * parser for 
 * literal string surrounded 
 * by arbitrary space
 */
function symbol($s)
{
    return seq_s(token(str($s)));
}

/**
 * parser one char of given value
 */
function char($s)
{
    return  sat(
        function ($in) use ($s) {
            return $in == $s;
        }
    );
}

/**
 * parser for string of given value
 */
function str($s)
{

    $fs = [];
    for($i = 0; $i<strlen($s);$i++){    
        $c = $s[$i];
        $fs[] = char($c);
    }
    return seq_s(...$fs);
}

/**
 * same as ident with
 * accounts for white space
 */
function identifier()
{
    return seq_s(token(ident()));
}

/**
 * same as nat
 * accounts for whitespace
 */
function natural()
{
    return seq_s(token(nat()));
}

/**
 * parser  that parses some
 * input separated by someother input
 * ie:
 * $p =  sepBy(alphanum(),symbol(','));
 * parse($p,'a,b,c'); //abc
 */ 
function sepBy($p,$sep)
{
    $fin = function ($res,$sp) {
        return is_array($res)?$res:[$res];
    };
    $item_sep = many(seq($fin, $p, skipMany1($sep)));
    //return seq_s(many(seq_s($p, skipMany1($sep))), $p);
    return
        seq(
            function ($rss,$r) {
                $rss[] = $r;
                return $rss  ;
            },
            $item_sep,
            $p
        );
}

/**
 * parses input not followed by $p
 */
function notFollowedBy($p)
{
    return function ($input) use ($p) {
        $res = parse($p, $input);
        //print_r([$res,$input,$buff]);
        if(isEmpty($res)) {
            return [['',$input]];
        }else{
            return [];
        }

    };

}

/**
 * parses input between open and 
 * close
 */
function between($open, $close, $p)
{
    return seq(
        function ($o,$p_res,$c) {
            return $p_res;
        },
        $open, $p, $close
    );
}

/**
 * parser that tries each parser from list
 * until one succeeds
 * 
 * @param  mixed[] $ps list of parsers
 * @return parser 
 */
function choice($ps)
{
    return function ($inp) use ($ps) {
        foreach($ps as $p){
            $res = parse($p, $inp);
            if (!isEmpty($res)) {
                return $res;
            }
        }
        return [];
    };
}

/**
 * apply parser $p zero or more times, skip results
 */
function skipMany($p)
{
    return orElse(skipMany1($p), _return(''));
}

/**
 * apply parser $p one or more times, skip results
 */
function skipMany1($p)
{
    return then(
        many1($p), function ($x) {
            return _return('');
        }
    );
}


/**
 * parser for arbitrary string surrounded
 * by quotes
 */
function quoted_str($q) 
{
    $c = char($q);
    return seq(
        function ($quote1,$str,$quote2) {
            return join('', $str);
        }, $c, many1(alphanum()), $c
    );
    
};


/**
 *  Given sql schema, we want to find the 'belongsTo' relationships.
 *  Can get all the tables that need to be created before the given table is created
 *  by looking at the foreign key constraints
 *
 *  Here is a simple parser that will parse a typical sql constraint clause
 *  
MySQL's foreign key constraint syntax
 *
 *  [CONSTRAINT [symbol]] FOREIGN KEY [index_name] (index_col_name, ...)
 *    REFERENCES tbl_name (index_col_name,...)
 *    [ON DELETE reference_option]
 *    [ON UPDATE reference_option]
 *
 * reference_option:
 *  RESTRICT | CASCADE | SET NULL | NO ACTION | SET DEFAULT
 */


    $constraint = str('CONSTRAINT');
    //match string in backticks surrounded by white space
    
    $symbol = token(quoted_str('`'));
    //match string 'FOREIGN KEY' surrounded by white space
    $FK = token(str('FOREIGN KEY'));

    $index_name = many(token(quoted_str('`')));
    //match parenthesis enclosed quoted strings separated by ','
    $index_cols = 
        between(
            char('('), char(')'),
            orElse( 
                sepBy(token(quoted_str('`')), symbol(',')), 
                seq_s(token(quoted_str('`')), notFollowedBy(symbol(',')))
            )
        );

    //match string 'REFERENCES'
    $REFS = token(str('REFERENCES'));
    //match quoted string `states`
    $table_name = token(quoted_str('`'));

    //match parenthesis enclosed list of quoted strings
    $ref_index_cols = $index_cols;

    $restrict = str('RESTRICT');
    $cascade = str('CASCADE');
    $set_nul = str('SET NULL');
    $no_action = str('NO ACTION');
    $set_def = str('SET DEFAULT'); 

    $options = choice([$restrict, $cascade, $set_nul, $no_action, $set_def]);
    $space_to_dash  = then(
        space(), function ($x) {
            return _return('-');
        }
    );
    $on_delete = str('ON DELETE');
    $on_update = str('ON UPDATE');
    $reference_option = seq_s(token(orElse($on_delete, $on_update)), $space_to_dash, token($options));

    
    //$result = function($constraint, $symbol,$FK, $index_name, $index_cols, $REFS, $table_name, $ref_index_cols, $del, $upd ){
    $result = function () {
        return func_get_args();
    };
/**
 * The beauty of applicative(?) parsers is that
 * they tend to look like the input they are parsing
 */
    $parser = seq(
        $result,
        /* [CONSTRAINT [symbol]] FOREIGN KEY [index_name] (index_col_name, ...) */
        $constraint, $symbol, $FK, $index_name, $index_cols, 
        /* REFERENCES tbl_name (index_col_name,...) */
        $REFS, $table_name, $ref_index_cols,
        /* [ON DELETE reference_option]
           [ON UPDATE reference_option] */
        $reference_option, $reference_option
    );

    
    $clause = 'CONSTRAINT `table_key_name` FOREIGN KEY (`fk_colum_1`) REFERENCES `ref_table_name` (`ref_key_1`,`ref_key_2`,`ref_key_3`) ON UPDATE NO ACTION ON DELETE NO ACTION';
    $res = parse($parser, $clause);
    print_r($res);
