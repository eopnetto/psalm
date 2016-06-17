<?php

namespace CodeInspector;

use PhpParser;

use CodeInspector\ExceptionHandler;
use CodeInspector\Issue\ForbiddenCode;
use CodeInspector\Issue\InvalidArgument;
use CodeInspector\Issue\InvalidNamespace;
use CodeInspector\Issue\InvalidIterator;
use CodeInspector\Issue\NullReference;
use CodeInspector\Issue\ParentNotFound;
use CodeInspector\Issue\PossiblyUndefinedVariable;
use CodeInspector\Issue\InvalidArrayAssignment;
use CodeInspector\Issue\InvalidScope;
use CodeInspector\Issue\InvalidStaticInvocation;
use CodeInspector\Issue\InvalidStaticVariable;
use CodeInspector\Issue\FailedTypeResolution;
use CodeInspector\Issue\UndefinedConstant;
use CodeInspector\Issue\UndefinedFunction;
use CodeInspector\Issue\UndefinedProperty;
use CodeInspector\Issue\UndefinedVariable;

class StatementsChecker
{
    protected $_stmts;

    protected $_source;
    protected $_all_vars = [];
    protected $_warn_vars = [];
    protected $_check_classes = true;
    protected $_check_variables = true;
    protected $_check_methods = true;
    protected $_check_consts = true;
    protected $_check_functions = true;
    protected $_class_name;
    protected $_class_extends;

    protected $_namespace;
    protected $_aliased_classes;
    protected $_file_name;
    protected $_is_static;
    protected $_absolute_class;
    protected $_type_checker;

    protected $_available_functions = [];

    protected $_require_file_name = null;

    protected static $_method_call_index = [];
    protected static $_existing_functions = [];
    protected static $_reflection_functions = [];
    protected static $_this_assignments = [];
    protected static $_this_calls = [];

    protected static $_existing_static_vars = [];
    protected static $_existing_properties = [];
    protected static $_check_string_fn = null;
    protected static $_mock_interfaces = [];

    public function __construct(StatementsSource $source, $enforce_variable_checks = false, $check_methods = true)
    {
        $this->_source = $source;
        $this->_check_classes = true;
        $this->_check_methods = $check_methods;

        $this->_check_consts = true;

        $this->_file_name = $this->_source->getFileName();
        $this->_aliased_classes = $this->_source->getAliasedClasses();
        $this->_namespace = $this->_source->getNamespace();
        $this->_is_static = $this->_source->isStatic();
        $this->_absolute_class = $this->_source->getAbsoluteClass();
        $this->_class_name = $this->_source->getClassName();
        $this->_class_extends = $this->_source->getParentClass();

        $this->_check_variables = !Config::getInstance()->doesInheritVariables($this->_file_name) || $enforce_variable_checks;

        $this->_type_checker = new TypeChecker($source, $this);
    }

    public function check(array $stmts, array &$vars_in_scope, array &$vars_possibly_in_scope, array &$for_vars_possibly_in_scope = [])
    {
        $has_returned = false;

        // register all functions first
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Function_) {
                $file_checker = FileChecker::getFileCheckerFromFileName($this->_file_name);
                $file_checker->registerFunction($stmt);
            }
        }

        foreach ($stmts as $stmt) {
            if ($has_returned && !($stmt instanceof PhpParser\Node\Stmt\Nop) && !($stmt instanceof PhpParser\Node\Stmt\InlineHTML)) {
                echo('Warning: Expressions after return/throw/continue in ' . $this->_file_name . ' on line ' . $stmt->getLine() . PHP_EOL);
                break;
            }

            if ($stmt instanceof PhpParser\Node\Stmt\If_) {
                $this->_checkIf($stmt, $vars_in_scope, $vars_possibly_in_scope, $for_vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\TryCatch) {
                $this->_checkTryCatch($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\For_) {
                $this->_checkFor($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Foreach_) {
                $this->_checkForeach($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\While_) {
                $this->_checkWhile($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Do_) {
                $this->_checkDo($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Const_) {
                foreach ($stmt->consts as $const) {
                    $this->_checkExpression($const->value, $vars_in_scope, $vars_possibly_in_scope);
                }

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Unset_) {
                // do nothing

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Return_) {
                $has_returned = true;
                $this->_checkReturn($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Throw_) {
                $has_returned = true;
                $this->_checkThrow($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Switch_) {
                $this->_checkSwitch($stmt, $vars_in_scope, $vars_possibly_in_scope, $for_vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Break_) {
                // do nothing

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Continue_) {
                $has_returned = true;

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Static_) {
                $this->_checkStatic($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Echo_) {
                foreach ($stmt->exprs as $expr) {
                    $this->_checkExpression($expr, $vars_in_scope, $vars_possibly_in_scope);
                }

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Function_) {
                $function_checker = new FunctionChecker($stmt, $this->_source);
                $function_checker->check();

            } elseif ($stmt instanceof PhpParser\Node\Expr) {
                $this->_checkExpression($stmt, $vars_in_scope, $vars_possibly_in_scope);

            } elseif ($stmt instanceof PhpParser\Node\Stmt\InlineHTML) {
                // do nothing

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Use_) {
                foreach ($stmt->uses as $use) {
                    $this->_aliased_classes[$use->alias] = implode('\\', $use->name->parts);
                }

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Global_) {
                foreach ($stmt->vars as $var) {
                    if ($var instanceof PhpParser\Node\Expr\Variable) {
                        if (is_string($var->name)) {
                            $vars_in_scope[$var->name] = Type::getMixed();
                            $vars_possibly_in_scope[$var->name] = true;
                        } else {
                            $this->_checkExpression($var, $vars_in_scope, $vars_possibly_in_scope);
                        }
                    }
                }

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->default) {
                        $this->_checkExpression($prop->default, $vars_in_scope, $vars_possibly_in_scope);
                    }

                    self::$_existing_static_vars[$this->_absolute_class . '::$' . $prop->name] = 1;
                }

            } elseif ($stmt instanceof PhpParser\Node\Stmt\ClassConst) {


            } elseif ($stmt instanceof PhpParser\Node\Stmt\Class_) {
                (new ClassChecker($stmt, $this->_source, $stmt->name))->check();

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Nop) {
                // do nothing

            } elseif ($stmt instanceof PhpParser\Node\Stmt\Namespace_) {
                if ($this->_namespace) {
                    if (ExceptionHandler::accepts(
                        new InvalidNamespace('Cannot redeclare namespace', $this->_require_file_name, $stmt->getLine())
                    )) {
                        return false;
                    }
                }

                $namespace_checker = new NamespaceChecker($stmt, $this->_source);
                $namespace_checker->check(true);
            } else {
                var_dump('Unrecognised statement in ' . $this->_file_name);
                var_dump($stmt);
            }
        }
    }

    /**
     * IF
     * all if/elseif/else blocks within an if block that
     * bleed out into the following scope redefine a variable
     * THEN
     * set the aggregated type of that variable afterwards
     *
     * these variables are stored in $redefined_vars
     *
     * ELSE IF
     * all if/elseif/else blocks within an if block that bleed out into
     * the following scope refute the if's conditional
     * OR
     * they agree with the if's conditional (without necessarily setting the variable)
     * THEN
     * set the aggregated type of that variable afterwards
     *
     * these variables are stored in $refuting_vars and $agreeing_vars
     *
     * @param  PhpParser\Node\Stmt\If_ $stmt
     * @param  array                   &$vars_in_scope
     * @param  array                   &$vars_possibly_in_scope
     * @param  array                   &$for_vars_possibly_in_scope
     * @return null|false
     */
    protected function _checkIf(PhpParser\Node\Stmt\If_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, array &$for_vars_possibly_in_scope)
    {
        if ($this->_checkCondition($stmt->cond, $vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }

        $if_types = $this->_type_checker->getTypeAssertions($stmt->cond, true);

        $has_leaving_statments = ScopeChecker::doesLeaveBlock($stmt->stmts, true, true);

        // we only need to negate the if types if there are throw/return/break/continue or else/elseif blocks
        $need_to_negate_if_types = $has_leaving_statments || $stmt->elseifs || $stmt->else;

        $can_negate_if_types = !($stmt->cond instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd);

        $negated_types = $if_types && $need_to_negate_if_types && $can_negate_if_types
                            ? TypeChecker::negateTypes($if_types)
                            : [];

        $negated_if_types = $negated_types;

        // if the if has an || in the conditional, we cannot easily reason about it
        if ($stmt->cond instanceof PhpParser\Node\Expr\BinaryOp && self::_containsBooleanOr($stmt->cond)) {
            $if_vars = array_merge([], $vars_in_scope);
            $if_vars_possibly_in_scope = array_merge([], $vars_possibly_in_scope);
        }
        else {
            $if_vars_reconciled = TypeChecker::reconcileKeyedTypes($if_types, $vars_in_scope, $this->_file_name, $stmt->getLine());
            if ($if_vars_reconciled === false) {
                return false;
            }
            $if_vars = $if_vars_reconciled;
            $if_vars_possibly_in_scope = array_merge($if_types, $vars_possibly_in_scope);
        }

        $old_if_vars = $if_vars;

        if ($this->check($stmt->stmts, $if_vars, $if_vars_possibly_in_scope, $for_vars_possibly_in_scope) === false) {
            return false;
        }

        $new_vars = null;
        $new_vars_possibly_in_scope = [];
        $redefined_vars = null;
        $refuting_vars = null;
        $agreeing_vars = null;
        $possibly_redefined_vars = [];
        $post_type_assertions = [];

        $visited_if = false;
        $visited_elseifs = false;

        if (count($stmt->stmts)) {
            if (!$has_leaving_statments) {
                $new_vars = array_diff_key($if_vars, $vars_in_scope);

                $redefined_vars = [];

                foreach ($old_if_vars as $if_var => $type) {
                    if ((string)$if_vars[$if_var] !== (string)$type) {
                        $redefined_vars[$if_var] = $if_vars[$if_var];
                    }
                }

                $possibly_redefined_vars = $redefined_vars;

                $refuting_vars = [];
                $agreeing_vars = [];

                foreach ($if_vars as $if_var => $type) {
                    // are we refuting or agreeing with all parts of this type?
                    if (isset($if_types[$if_var])) {
                        $is_negation = true;
                        $is_confirmation = true;

                        foreach ($type->types as $redefined_type_part) {
                            if (!TypeChecker::isNegation($redefined_type_part->value, $if_types[$if_var])) {
                                $is_negation = false;
                            }
                            else {
                                $is_confirmation = false;
                            }
                        }

                        if ($is_negation) {
                            $refuting_vars[$if_var] = $type;
                        }

                        if ($is_confirmation) {
                            $agreeing_vars[$if_var] = $type;
                        }
                    }
                }

                $visited_ifs = true;
            }
            else {
                $post_type_assertions = $negated_types;
            }

            $has_ending_statments = ScopeChecker::doesLeaveBlock($stmt->stmts, false, false);

            if (!$has_ending_statments) {
                $vars = array_diff_key($if_vars_possibly_in_scope, $vars_possibly_in_scope);

                // if we're leaving this block, add vars to outer for loop scope
                if ($has_leaving_statments) {
                    $for_vars_possibly_in_scope = array_merge($for_vars_possibly_in_scope, $vars);
                }
                else {
                    $new_vars_possibly_in_scope = $vars;
                }
            }
        }

        foreach ($stmt->elseifs as $elseif) {
            if ($negated_types) {
                $elseif_vars_reconciled = TypeChecker::reconcileKeyedTypes($negated_types, $vars_in_scope, $this->_file_name, $stmt->getLine());
                if ($elseif_vars_reconciled === false) {
                    return false;
                }
                $elseif_vars = $elseif_vars_reconciled;
            }
            else {
                $elseif_vars = array_merge([], $vars_in_scope);
            }

            $old_elseif_vars = $elseif_vars;

            $elseif_vars_possibly_in_scope = array_merge([], $vars_possibly_in_scope);

            $elseif_types = $this->_type_checker->getTypeAssertions($elseif->cond, true);

            if (!($elseif->cond instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd)) {
                $negated_types = array_merge($negated_types, TypeChecker::negateTypes($elseif_types));
            }
            else {
                $elseif_vars_reconciled = TypeChecker::reconcileKeyedTypes($elseif_types, $elseif_vars, $this->_file_name, $stmt->getLine());
                if ($elseif_vars_reconciled === false) {
                    return false;
                }
                $elseif_vars = $elseif_vars_reconciled;
            }

            if ($this->_checkElseIf($elseif, $elseif_vars, $elseif_vars_possibly_in_scope, $for_vars_possibly_in_scope) === false) {
                return false;
            }

            if (count($elseif->stmts)) {
                $has_leaving_statements = ScopeChecker::doesLeaveBlock($elseif->stmts, true, true);

                if (!$has_leaving_statements) {
                    $elseif_redefined_vars = [];

                    foreach ($old_elseif_vars as $elseif_var => $type) {
                        if ($elseif_vars[$elseif_var] !== $type) {
                            $elseif_redefined_vars[$elseif_var] = $elseif_vars[$elseif_var];
                        }
                    }

                    if ($redefined_vars === null) {
                        $redefined_vars = $elseif_redefined_vars;
                        $possibly_redefined_vars = $redefined_vars;
                    }
                    else {
                        foreach ($redefined_vars as $redefined_var => $type) {
                            if (!isset($elseif_redefined_vars[$redefined_var])) {
                                unset($redefined_vars[$redefined_var]);
                            }
                            else {
                                $redefined_vars[$redefined_var] = Type::combineUnionTypes($elseif_redefined_vars[$redefined_var], $type);
                            }
                        }

                        foreach ($elseif_redefined_vars as $var => $type) {
                            if ($type->isMixed()) {
                                $possibly_redefined_vars[$var] = $type;
                            }
                            else if (isset($possibly_redefined_vars[$var])) {
                                $possibly_redefined_vars[$var] = Type::combineUnionTypes($type, $possibly_redefined_vars[$var]);
                            }
                            else {
                                $possibly_redefined_vars[$var] = $type;
                            }
                        }
                    }

                    $elseif_refuting_vars = [];
                    $elseif_agreeing_vars = [];

                    foreach ($elseif_vars as $elseif_var => $type) {
                        // are we refuting or agreeing with all parts of this type?
                        if (isset($if_types[$elseif_var])) {
                            $is_negation = true;
                            $is_confirmation = true;

                            foreach ($type->types as $redefined_type_part) {
                                if (!TypeChecker::isNegation($redefined_type_part->value, $if_types[$elseif_var])) {
                                    $is_negation = false;
                                }
                                else {
                                    $is_confirmation = false;
                                }
                            }

                            if ($is_negation) {
                                $elseif_refuting_vars[$elseif_var] = $type;
                            }

                            if ($is_confirmation) {
                                $elseif_agreeing_vars[$elseif_var] = $type;
                            }
                        }
                    }

                    if ($refuting_vars === null) {
                        $refuting_vars = $elseif_refuting_vars;
                    }
                    else {
                        foreach ($refuting_vars as $var => $type) {
                            if (isset($elseif_refuting_vars[$var])) {
                                $refuting_vars[$var] = Type::combineUnionTypes($elseif_refuting_vars[$var], $type);
                            }
                            else {
                                unset($refuting_vars[$var]);
                            }
                        }
                    }

                    if ($agreeing_vars === null) {
                        $agreeing_vars = $elseif_agreeing_vars;
                    }
                    else {
                        foreach ($agreeing_vars as $var => $type) {
                            if (isset($elseif_agreeing_vars[$var])) {
                                $agreeing_vars[$var] = Type::combineUnionTypes($elseif_agreeing_vars[$var], $type);
                            }
                            else {
                                unset($agreeing_vars[$var]);
                            }
                        }
                    }

                    if ($new_vars === null) {
                        $new_vars = array_diff_key($elseif_vars, $vars_in_scope);
                    }
                    else {
                        foreach ($new_vars as $new_var => $type) {
                            if (!isset($elseif_vars[$new_var])) {
                                unset($new_vars[$new_var]);
                            }
                            else {
                                $new_vars[$new_var] = Type::combineUnionTypes($type, $elseif_vars[$new_var]);
                            }
                        }
                    }

                    $visited_elseifs = true;
                }
                else {
                    $post_type_assertions = $negated_types;
                }

                // has a return/throw at end
                $has_ending_statments = ScopeChecker::doesLeaveBlock($elseif->stmts, false, false);

                if (!$has_ending_statments) {
                    $vars = array_diff_key($elseif_vars_possibly_in_scope, $vars_possibly_in_scope);

                    // if we're leaving this block, add vars to outer for loop scope
                    if ($has_leaving_statements) {
                        $for_vars_possibly_in_scope = array_merge($vars, $for_vars_possibly_in_scope);
                    }
                    else {
                        $new_vars_possibly_in_scope = array_merge($vars, $new_vars_possibly_in_scope);
                    }
                }
            }
        }

        if ($stmt->else) {
            if ($negated_types) {
                $else_vars_reconciled = TypeChecker::reconcileKeyedTypes($negated_types, $vars_in_scope, $this->_file_name, $stmt->getLine());
                if ($else_vars_reconciled === false) {
                    return false;
                }
                $else_vars = $else_vars_reconciled;
            }
            else {
                $else_vars = array_merge([], $vars_in_scope);
            }

            $old_else_vars = $else_vars;

            $else_vars_possibly_in_scope = $vars_possibly_in_scope;

            if ($this->_checkElse($stmt->else, $else_vars, $else_vars_possibly_in_scope, $for_vars_possibly_in_scope) === false) {
                return false;
            }

            if (count($stmt->else->stmts)) {
                $has_leaving_statements = ScopeChecker::doesLeaveBlock($stmt->else->stmts, true, true);

                // if it doesn't end in a return
                if (!$has_leaving_statements) {
                    $else_redefined_vars = [];

                    foreach ($old_else_vars as $else_var => $type) {
                        if ($else_vars[$else_var] !== $type) {
                            $else_redefined_vars[$else_var] = $else_vars[$else_var];
                        }
                    }

                    if ($redefined_vars === null) {
                        $redefined_vars = $else_redefined_vars;
                        $possibly_redefined_vars = $redefined_vars;
                    }
                    else {
                        foreach ($redefined_vars as $redefined_var => $type) {
                            if (!isset($else_redefined_vars[$redefined_var])) {
                                unset($redefined_vars[$redefined_var]);
                            }
                            else {
                                $redefined_vars[$redefined_var] = Type::combineUnionTypes($else_redefined_vars[$redefined_var], $type);
                            }
                        }

                        foreach ($else_redefined_vars as $var => $type) {
                            if (isset($post_type_assertions[$var])) {
                                continue;
                            }

                            if ($type->isMixed()) {
                                $possibly_redefined_vars[$var] = $type;
                            }
                            else if (isset($possibly_redefined_vars[$var])) {
                                $possibly_redefined_vars[$var] = Type::combineUnionTypes($type, $possibly_redefined_vars[$var]);
                            }
                            else {
                                $possibly_redefined_vars[$var] = $type;
                            }
                        }
                    }

                    $else_refuting_vars = [];
                    $else_agreeing_vars = [];

                    foreach ($else_vars as $else_var => $type) {
                        // are we refuting or agreeing with all parts of this type?
                        if (isset($if_types[$else_var])) {
                            $is_negation = true;
                            $is_confirmation = true;

                            foreach ($type->types as $redefined_type_part) {
                                if (!TypeChecker::isNegation($redefined_type_part->value, $if_types[$else_var])) {
                                    $is_negation = false;
                                }
                                else {
                                    $is_confirmation = false;
                                }
                            }

                            if ($is_negation) {
                                $else_refuting_vars[$else_var] = $type;
                            }

                            if ($is_confirmation) {
                                $else_agreeing_vars[$else_var] = $type;
                            }
                        }
                    }

                    if ($refuting_vars === null) {
                        $refuting_vars = $else_refuting_vars;
                    }
                    else {
                        foreach ($refuting_vars as $var => $type) {
                            if (isset($else_refuting_vars[$var])) {
                                $refuting_vars[$var] = Type::combineUnionTypes($else_refuting_vars[$var], $type);
                            }
                            else {
                                unset($refuting_vars[$var]);
                            }
                        }
                    }

                    if ($agreeing_vars === null) {
                        $agreeing_vars = $else_agreeing_vars;
                    }
                    else {
                        foreach ($agreeing_vars as $var => $type) {
                            if (isset($else_agreeing_vars[$var])) {
                                $agreeing_vars[$var] = Type::combineUnionTypes($else_agreeing_vars[$var], $type);
                            }
                            else {
                                unset($agreeing_vars[$var]);
                            }
                        }
                    }

                    if ($new_vars === null) {
                        $new_vars = array_diff_key($else_vars, $vars_in_scope);
                    }
                    else {
                        foreach ($new_vars as $new_var => $type) {
                            if (!isset($else_vars[$new_var])) {
                                unset($new_vars[$new_var]);
                            }
                            else {
                                $new_vars[$new_var] = Type::combineUnionTypes($type, $else_vars[$new_var]);
                            }
                        }
                    }
                }
                else {
                    $refuting_vars = [];
                    $agreeing_vars = [];
                }

                // has a return/throw at end
                $has_ending_statments = ScopeChecker::doesLeaveBlock($stmt->else->stmts, false, false);

                if (!$has_ending_statments) {
                    $vars = array_diff_key($else_vars_possibly_in_scope, $vars_possibly_in_scope);

                    if ($has_leaving_statements) {
                        $for_vars_possibly_in_scope = array_merge($vars, $for_vars_possibly_in_scope);
                    }
                    else {
                        $new_vars_possibly_in_scope = array_merge($vars, $new_vars_possibly_in_scope);
                    }
                }

                if ($new_vars) {
                    // only update vars if there is an else
                    $vars_in_scope = array_merge($vars_in_scope, $new_vars);
                }

                if ($redefined_vars) {
                    $vars_in_scope = array_merge($vars_in_scope, $redefined_vars);
                    $redefined_vars = null;
                }
            }
        }
        else {
            if ($visited_elseifs) {
                $refuting_vars = [];
            }

            $redefined_vars = [];
            $agreeing_vars = [];
        }

        $vars_possibly_in_scope = array_merge($vars_possibly_in_scope, $new_vars_possibly_in_scope);

        if ($if_types) {
            /**
             * let's get the type assertions from the condition if it's a terminator
             * so that we can negate them going forward
             */
            if (ScopeChecker::doesLeaveBlock($stmt->stmts, false, false) && $negated_if_types) {
                $vars_in_scope_reconciled = TypeChecker::reconcileKeyedTypes($negated_if_types, $vars_in_scope, $this->_file_name, $stmt->getLine());

                if ($vars_in_scope_reconciled === false) {
                    return false;
                }

                $vars_in_scope = $vars_in_scope_reconciled;

                $vars_possibly_in_scope = array_merge($negated_if_types, $vars_possibly_in_scope);
            }

            if ($redefined_vars) {
                foreach ($if_types as $var => $type) {
                    $vars_in_scope[$var] = $redefined_vars[$var];
                }
            }

            if ($agreeing_vars) {
                foreach ($agreeing_vars as $var => $type) {
                    if (!isset($redefined_vars[$var])) {
                        $vars_in_scope[$var] = $type;
                    }
                }
            }

            if ($refuting_vars) {
                foreach ($refuting_vars as $var => $type) {
                    if (!isset($redefined_vars[$var])) {
                        $vars_in_scope[$var] = $type;
                    }
                }
            }
        }

        if ($possibly_redefined_vars) {
            foreach ($possibly_redefined_vars as $var => $type) {
                if (isset($vars_in_scope[$var]) && !isset($refuting_vars[$var]) && !isset($agreeing_vars[$var]) && !isset($redefined_vars[$var])) {
                    $vars_in_scope[$var] = Type::combineUnionTypes($vars_in_scope[$var], $type);
                }
            }
        }

        if ($post_type_assertions) {
            $vars_in_scope_reconciled = TypeChecker::reconcileKeyedTypes($post_type_assertions, $vars_in_scope, $this->_file_name, $stmt->getLine());

            if ($vars_in_scope_reconciled === false) {
                return false;
            }

            $vars_in_scope = $vars_in_scope_reconciled;
        }
    }

    protected function _checkElseIf(PhpParser\Node\Stmt\ElseIf_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, array &$for_vars_possibly_in_scope)
    {
        if ($this->_checkCondition($stmt->cond, $vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }

        $if_types = $this->_type_checker->getTypeAssertions($stmt->cond);

        $elseif_vars_reconciled = TypeChecker::reconcileKeyedTypes($if_types, $vars_in_scope, $this->_file_name, $stmt->getLine());

        if ($elseif_vars_reconciled === false) {
            return false;
        }

        $elseif_vars = $elseif_vars_reconciled;

        if ($this->check($stmt->stmts, $elseif_vars, $vars_possibly_in_scope, $for_vars_possibly_in_scope) === false) {
            return false;
        }

        $vars_in_scope = $elseif_vars;
    }

    protected function _checkElse(PhpParser\Node\Stmt\Else_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, array &$for_vars_possibly_in_scope)
    {
        $this->check($stmt->stmts, $vars_in_scope, $vars_possibly_in_scope, $for_vars_possibly_in_scope);
    }

    protected function _checkCondition(PhpParser\Node\Expr $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        return $this->_checkExpression($stmt, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkStatic(PhpParser\Node\Stmt\Static_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope = [])
    {
        foreach ($stmt->vars as $var) {
            if ($var instanceof PhpParser\Node\Stmt\StaticVar) {
                if (is_string($var->name)) {
                    if ($this->_check_variables) {
                        $vars_in_scope[$var->name] = Type::getMixed();
                        $vars_possibly_in_scope[$var->name] = true;
                        $this->registerVariable($var->name, $var->getLine());
                    }
                } else {
                    if ($this->_checkExpression($var->name, $vars_in_scope, $vars_possibly_in_scope) === false) {
                        return false;
                    }
                }

                if ($var->default) {
                    if ($this->_checkExpression($var->default, $vars_in_scope, $vars_possibly_in_scope) === false) {
                        return false;
                    }
                }
            } else {
                if ($this->_checkExpression($var, $vars_in_scope, $vars_possibly_in_scope) === false) {
                    return false;
                }
            }
        }
    }

    /**
     * @return false|null
     */
    protected function _checkExpression(PhpParser\Node\Expr $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope = [], $array_assignment = false)
    {
        if ($stmt instanceof PhpParser\Node\Expr\Variable) {
            return $this->_checkVariable($stmt, $vars_in_scope, $vars_possibly_in_scope, null, -1, $array_assignment);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Assign) {
            return $this->_checkAssignment($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\AssignOp) {
            return $this->_checkAssignmentOperation($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\MethodCall) {
            return $this->_checkMethodCall($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\StaticCall) {
            return $this->_checkStaticCall($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\ConstFetch) {
            return $this->_checkConstFetch($stmt);

        } elseif ($stmt instanceof PhpParser\Node\Scalar\String_) {
            if (self::$_check_string_fn) {
                call_user_func(self::$_check_string_fn, $stmt, $this->_file_name);
            }
            $stmt->inferredType = Type::getString();

        } elseif ($stmt instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
            // do nothing

        } elseif ($stmt instanceof PhpParser\Node\Scalar\MagicConst) {
            // do nothing

        } elseif ($stmt instanceof PhpParser\Node\Scalar\LNumber) {
            $stmt->inferredType = Type::getInt();

        } elseif ($stmt instanceof PhpParser\Node\Scalar\DNumber) {
            $stmt->inferredType = Type::getFloat();

        } elseif ($stmt instanceof PhpParser\Node\Expr\UnaryMinus) {
            return $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\UnaryPlus) {
            return $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Isset_) {
            foreach ($stmt->vars as $isset_var) {
                if ($isset_var instanceof PhpParser\Node\Expr\PropertyFetch &&
                    $isset_var->var instanceof PhpParser\Node\Expr\Variable &&
                    $isset_var->var->name === 'this' &&
                    is_string($isset_var->name)
                ) {
                    $var_id = 'this->' . $isset_var->name;
                    $vars_in_scope[$var_id] = Type::getMixed();
                    $vars_possibly_in_scope[$var_id] = true;
                }
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\ClassConstFetch) {
            return $this->_checkClassConstFetch($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\PropertyFetch) {
            return $this->_checkPropertyFetch($stmt, $vars_in_scope, $vars_possibly_in_scope, $array_assignment);

        } elseif ($stmt instanceof PhpParser\Node\Expr\StaticPropertyFetch) {
            return $this->_checkStaticPropertyFetch($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\BitwiseNot) {
            return $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp) {
            return $this->_checkBinaryOp($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\PostInc) {
            return $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\PostDec) {
            return $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\PreInc) {
            return $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\PreDec) {
            return $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\New_) {
            return $this->_checkNew($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Array_) {
            return $this->_checkArray($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Scalar\Encapsed) {
            return $this->_checkEncapsulatedString($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall) {
            return $this->_checkFunctionCall($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Ternary) {
            return $this->_checkTernary($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\BooleanNot) {
            return $this->_checkBooleanNot($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Empty_) {
            return $this->_checkEmpty($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Closure) {
            $closure_checker = new ClosureChecker($stmt, $this->_source);

            if ($this->_checkClosureUses($stmt, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }

            $use_vars = [];
            $use_vars_possibly_in_scope = [];

            if (!$this->_is_static) {
                $this_class = ClassChecker::getThisClass() && is_subclass_of(ClassChecker::getThisClass(), $this->_absolute_class) ?
                    ClassChecker::getThisClass() :
                    $this->_absolute_class;

                $use_vars['this'] = new Type\Union([new Type\Atomic($this_class)]);
            }

            foreach ($vars_in_scope as $var => $type) {
                if (strpos($var, 'this->') === 0) {
                    $use_vars[$var] = $type;
                }
            }

            foreach ($vars_possibly_in_scope as $var => $type) {
                if (strpos($var, 'this->') === 0) {
                    $use_vars_possibly_in_scope[$var] = true;
                }
            }

            foreach ($stmt->uses as $use) {
                $use_vars[$use->var] = isset($vars_in_scope[$use->var]) ? $vars_in_scope[$use->var] : Type::getMixed();
                $use_vars_possibly_in_scope[$use->var] = true;
            }

            $closure_checker->check($use_vars, $use_vars_possibly_in_scope, $this->_check_methods);

        } elseif ($stmt instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            return $this->_checkArrayAccess($stmt, $vars_in_scope, $vars_possibly_in_scope);

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Int_) {
            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
            $stmt->inferredType = Type::getInt();

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Double) {
            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
            $stmt->inferredType = Type::getDouble();

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Bool_) {
            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
            $stmt->inferredType = Type::getBool();

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\String_) {
            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
            $stmt->inferredType = Type::getString();

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Object_) {
            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
            $stmt->inferredType = Type::getObject();

        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Array_) {
            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
            $stmt->inferredType = Type::getArray();

        } elseif ($stmt instanceof PhpParser\Node\Expr\Clone_) {
            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }

            if (property_exists($stmt->expr, 'inferredType')) {
                $stmt->inferredType = $stmt->expr->inferredType;
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\Instanceof_) {
            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }

            if ($stmt->class instanceof PhpParser\Node\Name && !in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
                if ($this->_check_classes) {
                    if (ClassChecker::checkClassName($stmt->class, $this->_namespace, $this->_aliased_classes, $this->_file_name) === false) {
                        return false;
                    }
                }
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\Exit_) {
            // do nothing

        } elseif ($stmt instanceof PhpParser\Node\Expr\Include_) {
            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }

            $path_to_file = null;

            if ($stmt->expr instanceof PhpParser\Node\Scalar\String_) {
                $path_to_file = $stmt->expr->value;

                // attempts to resolve using get_include_path dirs
                $include_path = self::_resolveIncludePath($path_to_file, dirname($this->_file_name));
                $path_to_file = $include_path ? $include_path : $path_to_file;

                if ($path_to_file[0] !== '/') {
                    $path_to_file = getcwd() . '/' . $path_to_file;
                }
            }
            else {
                $path_to_file = self::_getPathTo($stmt->expr, $this->_file_name);
            }

            if ($path_to_file) {
                $reduce_pattern = '/\/[^\/]+\/\.\.\//';

                while (preg_match($reduce_pattern, $path_to_file)) {
                    $path_to_file = preg_replace($reduce_pattern, '/', $path_to_file);
                }

                // if the file is already included, we can't check much more
                if (in_array($path_to_file, get_included_files())) {
                    return;
                }

                if (in_array($path_to_file, FileChecker::getIncludesToIgnore())) {
                    return;
                }

                if (file_exists($path_to_file)) {
                    $include_stmts = FileChecker::getStatements($path_to_file);

                    $this->_require_file_name = $path_to_file;
                    $this->check($include_stmts, $vars_in_scope, $vars_possibly_in_scope);
                    return;
                }
            }

            $this->_check_classes = false;
            $this->_check_variables = false;

        } elseif ($stmt instanceof PhpParser\Node\Expr\Eval_) {
            $this->_check_classes = false;
            $this->_check_variables = false;

            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\AssignRef) {
            if ($stmt->var instanceof PhpParser\Node\Expr\Variable) {
                $vars_in_scope[$stmt->var->name] = Type::getMixed();
                $vars_possibly_in_scope[$stmt->var->name] = true;
                $this->registerVariable($stmt->var->name, $stmt->var->getLine());
            } else {
                if ($this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope) === false) {
                    return false;
                }
            }

            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\ErrorSuppress) {
            // do nothing

        } elseif ($stmt instanceof PhpParser\Node\Expr\ShellExec) {
            if (ExceptionHandler::accepts(
                new ForbiddenCode('Use of shell_exec', $this->_file_name, $stmt->getLine())
            )) {
                return false;
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\Print_) {
            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }

        } else {
            var_dump('Unrecognised expression in ' . $this->_file_name);
            var_dump($stmt);
        }
    }

    /**
     * @return false|null
     */
    protected function _checkVariable(PhpParser\Node\Expr\Variable $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, $method_id = null, $argument_offset = -1, $array_assignment = false)
    {
        if ($this->_is_static && $stmt->name === 'this') {
            if (ExceptionHandler::accepts(
                new InvalidStaticVariable('Invalid reference to $this in a static context', $this->_file_name, $stmt->getLine())
            )) {
                return false;
            }
        }

        if (!$this->_check_variables) {
            $stmt->inferredType = Type::getMixed();

            if (is_string($stmt->name)) {
                $vars_in_scope[$stmt->name] = Type::getMixed();
                $vars_possibly_in_scope[$stmt->name] = true;
            }

            return;
        }

        if (in_array($stmt->name, ['_SERVER', '_GET', '_POST', '_COOKIE', '_REQUEST', '_FILES', '_ENV', 'GLOBALS', 'argv'])) {
            return;
        }

        if (!is_string($stmt->name)) {
            return $this->_checkExpression($stmt->name, $vars_in_scope, $vars_possibly_in_scope);
        }

        if ($method_id && isset($vars_in_scope[$stmt->name]) && !$vars_in_scope[$stmt->name]->isMixed()) {
            if ($this->_checkFunctionArgumentType($vars_in_scope[$stmt->name], $method_id, $argument_offset, $this->_file_name, $stmt->getLine()) === false) {
                return false;
            }
        }

        if ($stmt->name === 'this') {
            return;
        }

        if ($method_id && $this->_isPassedByReference($method_id, $argument_offset)) {
            $this->_assignByRefParam($stmt, $method_id, $vars_in_scope, $vars_possibly_in_scope);
            return;
        }

        $var_name = $stmt->name;

        if (!isset($vars_in_scope[$var_name])) {
            if (!isset($vars_possibly_in_scope[$var_name]) || !isset($this->_all_vars[$var_name])) {
                if ($array_assignment) {
                    // if we're in an array assignment, let's assign the variable
                    // because PHP allows it

                    $vars_in_scope[$var_name] = Type::getArray();
                    $vars_possibly_in_scope[$var_name] = true;
                    $this->registerVariable($var_name, $stmt->getLine());
                }
                else {
                    if (ExceptionHandler::accepts(
                        new UndefinedVariable('Cannot find referenced variable $' . $var_name, $this->_file_name, $stmt->getLine())
                    )) {
                        return false;
                    }
                }
            }

            if (isset($this->_all_vars[$var_name]) && !isset($this->_warn_vars[$var_name])) {
                $this->_warn_vars[$var_name] = true;

                if (ExceptionHandler::accepts(
                    new PossiblyUndefinedVariable(
                        'Possibly undefined variable $' . $var_name .', first seen on line ' . $this->_all_vars[$var_name],
                        $this->_file_name,
                        $stmt->getLine()
                    )
                )) {
                    return false;
                }
            }

        } else {
            $stmt->inferredType = $vars_in_scope[$var_name];
        }
    }

    protected function _assignByRefParam(PhpParser\Node\Expr $stmt, $method_id, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($stmt instanceof PhpParser\Node\Expr\Variable) {
            $property_id = $stmt->name;
        }
        else if ($stmt instanceof PhpParser\Node\Expr\PropertyFetch && $stmt->var->name === 'this') {
            $property_id = $stmt->var->name . '->' . $stmt->name;
        }
        else {
            throw new \InvalidArgumentException('Bad property passed to _checkMethodParam');
        }

        if (!isset($vars_in_scope[$property_id])) {
            $vars_possibly_in_scope[$property_id] = true;
            $this->registerVariable($property_id, $stmt->getLine());

            if ($stmt instanceof PhpParser\Node\Expr\PropertyFetch && $this->_source->getMethodId()) {
                $this_method_id = $this->_source->getMethodId();

                if (!isset(self::$_this_assignments[$this_method_id])) {
                    self::$_this_assignments[$this_method_id] = [];
                }

                self::$_this_assignments[$this_method_id][$stmt->name] = Type::getMixed();
            }
        }

        $vars_in_scope[$property_id] = Type::getMixed();
    }

    protected function _checkPropertyFetch(PhpParser\Node\Expr\PropertyFetch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, $array_assignment = false)
    {
        if (!is_string($stmt->name)) {
            if ($this->_checkExpression($stmt->name, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
        }

        if ($stmt->var instanceof PhpParser\Node\Expr\Variable) {
            if ($stmt->var->name === 'this') {
                if (is_string($stmt->name)) {
                    return $this->_checkThisPropertyFetch($stmt, $vars_in_scope, $vars_possibly_in_scope, $array_assignment);
                }
            }

            return $this->_checkVariable($stmt->var, $vars_in_scope, $vars_possibly_in_scope);

        }

        return $this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkThisPropertyFetch(PhpParser\Node\Expr\PropertyFetch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, $array_assignment = false)
    {
        $class_checker = $this->_source->getClassChecker();

        if (!$class_checker) {
            if (ExceptionHandler::accepts(
                new InvalidScope('Cannot use $this when not inside class', $this->_file_name, $stmt->getLine())
            )) {
                return false;
            }
        }

        $var_id = self::getVarId($stmt);
        $property_names = $class_checker->getPropertyNames();

        if (isset($vars_in_scope[$var_id])) {
            $stmt->inferredType = $vars_in_scope[$var_id];
        }

        if (!in_array($stmt->name, $property_names)) {
            $property_id = $this->_absolute_class . '::' . $stmt->name;

            $var_defined = isset($vars_in_scope[$var_id]) || isset($vars_possibly_in_scope[$var_id]);

            if ((ClassChecker::getThisClass() && !$var_defined) || (!ClassChecker::getThisClass() && !$var_defined && !self::_propertyExists($property_id))) {
                if ($array_assignment) {
                    // if we're in an array assignment, let's assign the variable
                    // because PHP allows it

                    $vars_in_scope[$var_id] = Type::getArray();
                    $vars_possibly_in_scope[$var_id] = true;
                    $this->registerVariable($var_id, $stmt->getLine());
                }
                else {
                    if (ExceptionHandler::accepts(
                        new UndefinedProperty('$' . $var_id . ' is not defined', $this->_file_name, $stmt->getLine())
                    )) {
                        return false;
                    }
                }

            }
        }
    }

    protected function _checkNew(PhpParser\Node\Expr\New_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $absolute_class = null;

        if ($stmt->class instanceof PhpParser\Node\Name && !in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
            if ($this->_check_classes) {
                if (ClassChecker::checkClassName($stmt->class, $this->_namespace, $this->_aliased_classes, $this->_file_name) === false) {
                    return false;
                }

                $absolute_class = ClassChecker::getAbsoluteClassFromName($stmt->class, $this->_namespace, $this->_aliased_classes);
                $stmt->inferredType = new Type\Union([new Type\Atomic($absolute_class)]);
            }
        }

        if ($absolute_class) {
            $method_id = $absolute_class . '::__construct';

            if ($this->_checkMethodParams($stmt->args, $method_id, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
        }
    }

    protected function _checkArray(PhpParser\Node\Expr\Array_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        // if the array is empty, this special type allows us to match any other array type against it
        if (empty($stmt->items)) {
            $stmt->inferredType = new Type\Union([new Type\Generic('array', [new Type\Atomic('empty')], true)]);
            return;
        }

        foreach ($stmt->items as $item) {
            if ($item->key) {
                if ($this->_checkExpression($item->key, $vars_in_scope, $vars_possibly_in_scope) === false) {
                    return false;
                }
            }

            if ($this->_checkExpression($item->value, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
        }

        $stmt->inferredType = Type::getArray();
    }

    protected function _checkTryCatch(PhpParser\Node\Stmt\TryCatch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $this->check($stmt->stmts, $vars_in_scope, $vars_possibly_in_scope);

        foreach ($stmt->catches as $catch) {
            $catch_vars_in_scope = array_merge([], $vars_in_scope);

            if ($catch->type) {
                $catch_vars_in_scope[$catch->var] = new Type\Union([
                    new Type\Atomic(ClassChecker::getAbsoluteClassFromName($catch->type, $this->_namespace, $this->_aliased_classes))
                ]);
            }
            else {
                $catch_vars_in_scope[$catch->var] = Type::getMixed();
            }

            $vars_possibly_in_scope[$catch->var] = true;

            $this->registerVariable($catch->var, $catch->getLine());

            if ($this->_check_classes) {
                if (ClassChecker::checkClassName($catch->type, $this->_namespace, $this->_aliased_classes, $this->_file_name) === false) {
                    return;
                }
            }

            $this->check($catch->stmts, $catch_vars_in_scope, $vars_possibly_in_scope);

            foreach ($catch_vars_in_scope as $catch_var => $type) {
                if ($catch->var !== $catch_var && isset($vars_in_scope[$catch_var]) && (string) $vars_in_scope[$catch_var] !== (string) $type) {
                    $vars_in_scope[$catch_var] = Type::combineUnionTypes($vars_in_scope[$catch_var], $type);
                }
            }
        }

        if ($stmt->finallyStmts) {
            $this->check($stmt->finallyStmts, $vars_in_scope, $vars_possibly_in_scope);
        }
    }

    protected function _checkFor(PhpParser\Node\Stmt\For_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $for_vars = array_merge([], $vars_in_scope);

        foreach ($stmt->init as $init) {
            if ($this->_checkExpression($init, $for_vars, $vars_possibly_in_scope) === false) {
                return false;
            }
        }

        foreach ($stmt->cond as $condition) {
            if ($this->_checkCondition($condition, $for_vars, $vars_possibly_in_scope) === false) {
                return false;
            }
        }

        foreach ($stmt->loop as $expr) {
            if ($this->_checkExpression($expr, $for_vars, $vars_possibly_in_scope) === false) {
                return false;
            }
        }

        $for_vars_possibly_in_scope = [];

        $this->check($stmt->stmts, $for_vars, $vars_possibly_in_scope, $for_vars_possibly_in_scope);

        foreach ($vars_in_scope as $var => $type) {
            if ($type->isMixed()) {
                continue;
            }

            if ($for_vars[$var]->isMixed()) {
                $vars_in_scope[$var] = $for_vars[$var];
            }

            if ((string) $for_vars[$var] !== (string) $type) {
                $vars_in_scope[$var] = Type::combineUnionTypes($vars_in_scope[$var], $for_vars[$var]);
            }
        }

        $vars_possibly_in_scope = array_merge($for_vars_possibly_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkForeach(PhpParser\Node\Stmt\Foreach_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }

        $foreach_vars = [];

        if ($stmt->keyVar) {
            $foreach_vars[$stmt->keyVar->name] = Type::getMixed();
            $vars_possibly_in_scope[$stmt->keyVar->name] = true;
            $this->registerVariable($stmt->keyVar->name, $stmt->getLine());
        }

        if ($stmt->valueVar) {
            $value_type = null;

            $var_id = self::getVarId($stmt->expr);

            $iterator_type = isset($vars_in_scope[$var_id]) ? $vars_in_scope[$var_id] : null;

            if ($iterator_type) {
                foreach ($iterator_type->types as $return_type) {
                    switch ($return_type->value) {
                        case 'mixed':
                        case 'array':
                            // do nothing
                            break;

                        case 'null':
                            if (ExceptionHandler::accepts(
                                new NullReference('Cannot iterate over ' . $return_type->value, $this->_file_name, $stmt->getLine())
                            )) {
                                return false;
                            }
                            break;

                        case 'string':
                        case 'void':
                        case 'int':
                            if (ExceptionHandler::accepts(
                                new InvalidIterator('Cannot iterate over ' . $return_type->value, $this->_file_name, $stmt->getLine())
                            )) {
                                return false;
                            }
                            break;

                        default:
                            if ($iterator_type instanceof Type\Generic) {
                                $value_type = $iterator_type->type_params[0];
                            }

                            if ($return_type->value !== 'array' && $return_type->value !== 'Traversable' && $return_type->value !== $this->_class_name) {
                                if (ClassChecker::checkAbsoluteClass($return_type->value, $stmt, $this->_file_name) === false) {
                                    return false;
                                }
                            }
                    }
                }
            }

            $foreach_vars[$stmt->valueVar->name] = $value_type ? $value_type : Type::getMixed();
            $vars_possibly_in_scope[$stmt->valueVar->name] = true;
            $this->registerVariable($stmt->valueVar->name, $stmt->getLine());
        }

        $foreach_vars = array_merge($vars_in_scope, $foreach_vars);

        $foreach_vars_possibly_in_scope = [];

        $this->check($stmt->stmts, $foreach_vars, $vars_possibly_in_scope, $foreach_vars_possibly_in_scope);

        foreach ($vars_in_scope as $var => $type) {
            if ($type->isMixed()) {
                continue;
            }

            if ($foreach_vars[$var]->isMixed()) {
                $vars_in_scope[$var] = $foreach_vars[$var];
            }

            if ((string) $foreach_vars[$var] !== (string) $type) {
                $vars_in_scope[$var] = Type::combineUnionTypes($vars_in_scope[$var], $foreach_vars[$var]);
            }
        }

        $vars_possibly_in_scope = array_merge($foreach_vars_possibly_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkWhile(PhpParser\Node\Stmt\While_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $while_vars = array_merge([], $vars_in_scope);

        if ($this->_checkCondition($stmt->cond, $while_vars, $vars_possibly_in_scope) === false) {
            return false;
        }

        $while_types = $this->_type_checker->getTypeAssertions($stmt->cond, true);

        // if the while has an or as the main component, we cannot safely reason about it
        if ($stmt->cond instanceof PhpParser\Node\Expr\BinaryOp && self::_containsBooleanOr($stmt->cond)) {
            // do nothing
        }
        else {
            $while_vars_in_scope_reconciled = TypeChecker::reconcileKeyedTypes($while_types, $while_vars, $this->_file_name, $stmt->getLine());

            if ($while_vars_in_scope_reconciled === false) {
                return false;
            }

            $while_vars = $while_vars_in_scope_reconciled;
        }

        if ($this->check($stmt->stmts, $while_vars, $vars_possibly_in_scope) === false) {
            return false;
        }

        foreach ($vars_in_scope as $var => $type) {
            if ($type->isMixed()) {
                continue;
            }

            if ($while_vars[$var]->isMixed()) {
                $vars_in_scope[$var] = $while_vars[$var];
            }

            if ($while_vars[$var] !== $type) {
                $vars_in_scope[$var]->types = array_merge($vars_in_scope[$var]->types, $while_vars[$var]->types);
            }
        }
    }

    protected function _checkDo(PhpParser\Node\Stmt\Do_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($this->check($stmt->stmts, $vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }

        $vars_in_scope_copy = array_merge([], $vars_in_scope);

        return $this->_checkCondition($stmt->cond, $vars_in_scope_copy, $vars_possibly_in_scope);
    }

    protected function _checkBinaryOp(PhpParser\Node\Expr\BinaryOp $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, $nesting = 0)
    {
        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Concat && $nesting > 20) {
            // ignore deeply-nested string concatenation
        }
        else if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd) {
            $left_type_assertions = $this->_type_checker->getTypeAssertions($stmt->left, true);

            if ($this->_checkExpression($stmt->left, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }

            // while in an and, we allow scope to boil over to support
            // statements of the form if ($x && $x->foo())
            $op_vars_in_scope = TypeChecker::reconcileKeyedTypes($left_type_assertions, $vars_in_scope, $this->_file_name, $stmt->getLine());

            if ($op_vars_in_scope === false) {
                return false;
            }

            if ($this->_checkExpression($stmt->right, $op_vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
        }
        else if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr) {
            $left_type_assertions = $this->_type_checker->getTypeAssertions($stmt->left, true);

            $negated_type_assertions = TypeChecker::negateTypes($left_type_assertions);

            if ($this->_checkExpression($stmt->left, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }

            // while in an or, we allow scope to boil over to support
            // statements of the form if ($x === null || $x->foo())
            $op_vars_in_scope = TypeChecker::reconcileKeyedTypes($negated_type_assertions, $vars_in_scope, $this->_file_name, $stmt->getLine());

            if ($op_vars_in_scope === false) {
                return false;
            }

            if ($this->_checkExpression($stmt->right, $op_vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
        }
        else {
            if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
                $stmt->inferredType = Type::getString();
            }

            if ($stmt->left instanceof PhpParser\Node\Expr\BinaryOp) {
                if ($this->_checkBinaryOp($stmt->left, $vars_in_scope, $vars_possibly_in_scope, ++$nesting) === false) {
                    return false;
                }
            }
            else {
                if ($this->_checkExpression($stmt->left, $vars_in_scope, $vars_possibly_in_scope) === false) {
                    return false;
                }
            }

            if ($stmt->right instanceof PhpParser\Node\Expr\BinaryOp) {
                if ($this->_checkBinaryOp($stmt->right, $vars_in_scope, $vars_possibly_in_scope, ++$nesting) === false) {
                    return false;
                }
            }
            else {
                if ($this->_checkExpression($stmt->right, $vars_in_scope, $vars_possibly_in_scope) === false) {
                    return false;
                }
            }
        }

        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\Equal ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\NotEqual ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\Identical ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\Greater ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\GreaterOrEqual ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\Smaller ||
            $stmt instanceof PhpParser\Node\Expr\BinaryOp\SmallerOrEqual
        ) {
            $stmt->inferredType = Type::getBool();
        }
    }

    protected function _checkAssignment(PhpParser\Node\Expr\Assign $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }

        $type_in_comments = null;
        $type_in_comments_var_id = null;
        $doc_comment = $stmt->getDocComment();

        if ($doc_comment) {
            $comments = self::parseDocComment($doc_comment);

            if ($comments && isset($comments['specials']['var'][0])) {
                $var_parts = array_filter(preg_split('/[\s\t]+/', $comments['specials']['var'][0]));

                if ($var_parts) {
                    $type_in_comments = $var_parts[0];

                    if ($type_in_comments[0] === strtoupper($type_in_comments[0])) {
                        $type_in_comments = ClassChecker::getAbsoluteClassFromString($type_in_comments, $this->_namespace, $this->_aliased_classes);
                    }

                    // support PHPStorm-style docblocks like
                    // @var Type $variable
                    if (count($var_parts) > 1 && $var_parts[1][0] === '$') {
                        $type_in_comments_var_id = substr($var_parts[1], 1);
                    }
                }
            }
        }

        $var_id = self::getVarId($stmt->var);

        if ($type_in_comments_var_id && $type_in_comments_var_id !== $var_id) {
            if (isset($vars_in_scope[$type_in_comments_var_id])) {
                $vars_in_scope[$type_in_comments_var_id] = Type::parseString($type_in_comments);
            }

            $type_in_comments = null;
        }

        if ($type_in_comments) {
            $return_type = Type::parseString($type_in_comments);
        }
        elseif (isset($stmt->expr->inferredType)) {
            $return_type = $stmt->expr->inferredType;
        }
        else {
            $return_type = Type::getMixed();
        }

        $stmt->inferredType = $return_type;

        if ($stmt->var instanceof PhpParser\Node\Expr\Variable && is_string($stmt->var->name)) {
            $vars_in_scope[$var_id] = $return_type;
            $vars_possibly_in_scope[$var_id] = true;
            $this->registerVariable($var_id, $stmt->var->getLine());

        } elseif ($stmt->var instanceof PhpParser\Node\Expr\List_) {
            foreach ($stmt->var->vars as $var) {
                if ($var) {
                    $vars_in_scope[$var->name] = Type::getMixed();
                    $vars_possibly_in_scope[$var->name] = true;
                    $this->registerVariable($var->name, $var->getLine());
                }
            }

        } else if ($stmt->var instanceof PhpParser\Node\Expr\ArrayDimFetch) {

            if ($this->_checkArrayAssignment($stmt->var, $vars_in_scope, $vars_possibly_in_scope, $return_type) === false) {
                return false;
            }

        } else if ($stmt->var instanceof PhpParser\Node\Expr\PropertyFetch &&
                    $stmt->var->var instanceof PhpParser\Node\Expr\Variable &&
                    $stmt->var->var->name === 'this' &&
                    is_string($stmt->var->name)) {

            $method_id = $this->_source->getMethodId();

            if (!isset(self::$_this_assignments[$method_id])) {
                self::$_this_assignments[$method_id] = [];
            }

            $property_id = $this->_absolute_class . '::' . $stmt->var->name;
            self::$_existing_properties[$property_id] = 1;

            $vars_in_scope[$var_id] = $return_type;
            $vars_possibly_in_scope[$var_id] = true;

            // right now we have to settle for mixed
            self::$_this_assignments[$method_id][$stmt->var->name] = Type::getMixed();
        }

        if ($var_id && isset($vars_in_scope[$var_id]) && $vars_in_scope[$var_id] instanceof Type\Void) {
            if (ExceptionHandler::accepts(
                new FailedTypeResolution('Cannot assign $' . $var_id . ' to type void', $this->_file_name, $stmt->getLine())
            )) {
                return false;
            }
        }
    }

    public static function getVarId(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\Variable && is_string($stmt->name)) {
            return $stmt->name;
        }
        else if ($stmt instanceof PhpParser\Node\Expr\PropertyFetch &&
            $stmt->var instanceof PhpParser\Node\Expr\Variable &&
            is_string($stmt->name)) {

            $object_id = self::getVarId($stmt->var);

            if (!$object_id) {
                return null;
            }

            return $object_id . '->' . $stmt->name;
        }

        return null;
    }

    protected function _checkArrayAssignment(PhpParser\Node\Expr\ArrayDimFetch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, Type\Union $assignment_type)
    {
        if ($this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope, true) === false) {
            return false;
        }

        $var_id = self::getVarId($stmt->var);

        if (isset($stmt->var->inferredType)) {
            $return_type = $stmt->var->inferredType;

            if (!$return_type->isMixed()) {

                foreach ($return_type->types as &$type) {
                    if ($type->isScalar()) {
                        if (ExceptionHandler::accepts(
                            new InvalidArrayAssignment('Cannot assign value on variable $' . $var_id . ' of scalar type ' . $type->value, $this->_file_name, $stmt->getLine())
                        )) {
                            return false;
                        }

                        continue;
                    }
                    $refined_type = $this->_refineArrayType($type, $assignment_type, $var_id, $stmt->getLine());

                    if ($refined_type === false) {
                        return false;
                    }

                    $type = $refined_type;
                }

                $vars_in_scope[$var_id] = $return_type;
            }
        }
    }

    /**
     *
     * @param  Type\Atomic $type
     * @param  string      $var_id
     * @param  int         $line_number
     * @return Type\Atomic
     */
    protected function _refineArrayType(Type\Atomic $type, Type\Union $assignment_type, $var_id, $line_number)
    {
        if ($type->value === 'null') {
            if (ExceptionHandler::accepts(
                new NullReference('Cannot assign value on possibly null array ' . $var_id, $this->_file_name, $line_number)
            )) {
                return false;
            }

            return $type;
        }

        if ($type->value !== 'array' && !ClassChecker::classImplements($type->value, 'ArrayAccess')) {
            if (ExceptionHandler::accepts(
                new InvalidArrayAssignment('Cannot assign value on variable ' . $var_id . ' that does not implement ArrayAccess', $this->_file_name, $line_number)
            )) {
                return false;
            }

            return $type;
        }

        if ($type instanceof Type\Generic) {
            if ($type->is_empty) {
                // boil this down to a regular array
                if ($assignment_type->isMixed()) {
                    return new Type\Atomic($type->value);
                }

                $type->type_params = array_values($assignment_type->types);
                $type->is_empty = false;
                return $type;
            }

            $array_type = $type->type_params[0] instanceof Type\Union ? $type->type_params[0] : new Type\Union([$type->type_params[0]]);

            if ((string) $array_type !== (string) $assignment_type) {
                $type->type_params[0] = Type::combineUnionTypes($array_type, $assignment_type);
                return $type;
            }
        }



        return $type;
    }

    protected function _checkAssignmentOperation(PhpParser\Node\Expr\AssignOp $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }

        return $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkMethodCall(PhpParser\Node\Expr\MethodCall $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }

        $class_type = null;
        $method_id = null;

        if ($stmt->var instanceof PhpParser\Node\Expr\Variable) {
            if (!is_string($stmt->var->name)) {
                if ($this->_checkExpression($stmt->var->name, $vars_in_scope, $vars_possibly_in_scope) === false) {
                    return false;
                }
            }
            else if ($stmt->var->name === 'this' && !$this->_class_name) {
                if (ExceptionHandler::accepts(
                    new InvalidScope('Use of $this in non-class context', $this->_file_name, $stmt->getLine())
                )) {
                    return false;
                }
            }
        } elseif ($stmt->var instanceof PhpParser\Node\Expr) {
            if ($this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
        }

        $var_id = self::getVarId($stmt->var);

        $class_type = isset($vars_in_scope[$var_id]) ? $vars_in_scope[$var_id] : null;

        // make sure we stay vague here
        if (!$class_type) {
            $stmt->inferredType = Type::getMixed();
        }

        if ($stmt->var instanceof PhpParser\Node\Expr\Variable && $stmt->var->name === 'this' && is_string($stmt->name)) {
            $this_method_id = $this->_source->getMethodId();

            if (!isset(self::$_this_calls[$this_method_id])) {
                self::$_this_calls[$this_method_id] = [];
            }

            self::$_this_calls[$this_method_id][] = $stmt->name;

            if (ClassChecker::getThisClass() &&
                (
                    ClassChecker::getThisClass() === $this->_absolute_class ||
                    is_subclass_of(ClassChecker::getThisClass(), $this->_absolute_class) ||
                    trait_exists($this->_absolute_class)
                )) {

                $method_id = $this->_absolute_class . '::' . $stmt->name;

                if ($this->_checkInsideMethod($method_id, $vars_in_scope, $vars_possibly_in_scope) === false) {
                    return false;
                }
            }
        }

        if (!$this->_check_methods) {
            return;
        }

        if ($class_type && is_string($stmt->name)) {
            foreach ($class_type->types as $type) {
                $absolute_class = $type->value;

                switch ($absolute_class) {
                    case 'null':
                        if (ExceptionHandler::accepts(
                            new NullReference('Cannot call method ' . $stmt->name . ' on possibly null variable ' . $class_type, $this->_file_name, $stmt->getLine())
                        )) {
                            return false;
                        }
                        break;

                    case 'int':
                    case 'bool':
                    case 'array':
                        if (ExceptionHandler::accepts(
                            new InvalidArgument('Cannot call method ' . $stmt->name . ' on ' . $class_type . ' variable', $this->_file_name, $stmt->getLine())
                        )) {
                            return false;
                        }
                        break;

                    case 'mixed':
                        break;

                    default:
                        if ($absolute_class[0] === strtoupper($absolute_class[0]) && !method_exists($absolute_class, '__call') && !self::isMock($absolute_class)) {
                            if (ClassChecker::checkAbsoluteClass($absolute_class, $stmt, $this->_file_name) === false) {
                                return false;
                            }

                            $method_id = $absolute_class . '::' . $stmt->name;

                            if (!isset(self::$_method_call_index[$method_id])) {
                                self::$_method_call_index[$method_id] = [];
                            }

                            if ($this->_source instanceof ClassMethodChecker) {
                                self::$_method_call_index[$method_id][] = $this->_source->getMethodId();
                            }
                            else {
                                self::$_method_call_index[$method_id][] = $this->_source->getFileName();
                            }

                            if (ClassMethodChecker::checkMethodExists($method_id, $this->_file_name, $stmt) === false) {
                                return false;
                            }

                            if (!($this->_source->getSource() instanceof TraitChecker)) {
                                $calling_context = $this->_absolute_class;

                                if (ClassChecker::getThisClass() && is_subclass_of(ClassChecker::getThisClass(), $this->_absolute_class)) {
                                    $calling_context = $this->_absolute_class;
                                }

                                ClassMethodChecker::checkMethodVisibility($method_id, $calling_context, $this->_file_name, $stmt->getLine());
                            }

                            $return_types = ClassMethodChecker::getMethodReturnTypes($method_id);

                            if ($return_types) {
                                $return_types = self::_fleshOutReturnTypes($return_types, $stmt->args, $method_id);

                                $stmt->inferredType = $return_types;
                            }
                        }
                }
            }
        }

        if ($this->_checkMethodParams($stmt->args, $method_id, $vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }
    }

    protected function _checkInsideMethod($method_id, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $method_checker = ClassChecker::getMethodChecker($method_id);

        if ($method_checker && $method_checker->getMethodId() !== $this->_source->getMethodId()) {
            $this_vars_in_scope = [];

            $this_vars_possibly_in_scope = [];

            foreach ($vars_possibly_in_scope as $var => $type) {
                if (strpos($var, 'this->') === 0) {
                    $this_vars_possibly_in_scope[$var] = true;
                }
            }

            foreach ($vars_in_scope as $var => $type) {
                if (strpos($var, 'this->') === 0) {
                    $this_vars_in_scope[$var] = $type;
                }
            }

            $method_checker->check($this_vars_in_scope, $this_vars_possibly_in_scope);

            foreach ($this_vars_in_scope as $var => $type) {
                $vars_possibly_in_scope[$var] = true;
            }

            foreach ($this_vars_in_scope as $var => $type) {
                $vars_in_scope[$var] = $type;
            }
        }
    }

    protected function _checkClosureUses(PhpParser\Node\Expr\Closure $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        foreach ($stmt->uses as $use) {
            if (!isset($vars_in_scope[$use->var])) {
                if ($use->byRef) {
                    $vars_in_scope[$use->var] = Type::getMixed();
                    $vars_possibly_in_scope[$use->var] = true;
                    $this->registerVariable($use->var, $use->getLine());
                    return;
                }

                if (!isset($vars_possibly_in_scope[$use->var])) {
                    if (ExceptionHandler::accepts(
                        new UndefinedVariable('Cannot find referenced variable $' . $use->var, $this->_file_name, $use->getLine())
                    )) {
                        return false;
                    }
                }

                if (isset($this->_all_vars[$use->var])) {
                    if (!isset($this->_warn_vars[$use->var])) {
                        $this->_warn_vars[$use->var] = true;
                        if (ExceptionHandler::accepts(
                            new PossiblyUndefinedVariable(
                                'Possibly undefined variable $' . $use->var . ', first seen on line ' . $this->_all_vars[$use->var],
                                $this->_file_name,
                                $use->getLine()
                            )
                        )) {
                            return false;
                        }
                    }

                    return;
                }

                if (ExceptionHandler::accepts(
                    new UndefinedVariable('Cannot find referenced variable $' . $use->var, $this->_file_name, $use->getLine())
                )) {
                    return false;
                }
            }
        }
    }

    /**
     * @return void
     */
    protected function _checkStaticCall(PhpParser\Node\Expr\StaticCall $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($stmt->class instanceof PhpParser\Node\Expr\Variable || $stmt->class instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            // this is when calling $some_class::staticMethod() - which is a shitty way of doing things
            // because it can't be statically type-checked
            return;
        }

        $method_id = null;
        $absolute_class = null;

        if (count($stmt->class->parts) === 1 && in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
            if ($stmt->class->parts[0] === 'parent') {
                if ($this->_class_extends === null) {
                    if (ExceptionHandler::accepts(
                        new ParentNotFound('Cannot call method on parent as this class does not extend another', $this->_file_name, $stmt->getLine())
                    )) {
                        return false;
                    }
                }

                $absolute_class = $this->_class_extends;
            } else {
                $absolute_class = ($this->_namespace ? $this->_namespace . '\\' : '') . $this->_class_name;
            }

        } elseif ($this->_check_classes) {
            if (ClassChecker::checkClassName($stmt->class, $this->_namespace, $this->_aliased_classes, $this->_file_name) === false) {
                return false;
            }
            $absolute_class = ClassChecker::getAbsoluteClassFromName($stmt->class, $this->_namespace, $this->_aliased_classes);
        }

        if (!$this->_check_methods) {
            return;
        }

        if ($stmt->class->parts === ['parent'] && is_string($stmt->name)) {
            if (ClassChecker::getThisClass()) {
                $method_id = $absolute_class . '::' . $stmt->name;

                if ($this->_checkInsideMethod($method_id, $vars_in_scope, $vars_possibly_in_scope) === false) {
                    return false;
                }
            }
        }

        if ($absolute_class && is_string($stmt->name) && !method_exists($absolute_class, '__callStatic') && !self::isMock($absolute_class)) {
            $method_id = $absolute_class . '::' . $stmt->name;

            if (!isset(self::$_method_call_index[$method_id])) {
                self::$_method_call_index[$method_id] = [];
            }

            if ($this->_source instanceof ClassMethodChecker) {
                self::$_method_call_index[$method_id][] = $this->_source->getMethodId();
            }
            else {
                self::$_method_call_index[$method_id][] = $this->_source->getFileName();
            }

            ClassMethodChecker::checkMethodExists($method_id, $this->_file_name, $stmt);
            ClassMethodChecker::checkMethodVisibility($method_id, $this->_absolute_class, $this->_file_name, $stmt->getLine());

            if ($this->_is_static) {
                if (!ClassMethodChecker::isGivenMethodStatic($method_id)) {
                    if (ExceptionHandler::accepts(
                        new InvalidStaticInvocation('Method ' . $method_id . ' is not static', $this->_file_name, $stmt->getLine())
                    )) {
                        return false;
                    }
                }
            }
            else {
                if ($stmt->class->parts[0] === 'self' && $stmt->name !== '__construct') {
                    if (!ClassMethodChecker::isGivenMethodStatic($method_id)) {
                        if (ExceptionHandler::accepts(
                            new InvalidStaticInvocation('Cannot call non-static method ' . $method_id . ' as if it were static', $this->_file_name, $stmt->getLine())
                        )) {
                            return false;
                        }
                    }
                }
            }

            $return_types = ClassMethodChecker::getMethodReturnTypes($method_id);

            if ($return_types) {
                $return_types = self::_fleshOutReturnTypes($return_types, $stmt->args, $method_id);
                $stmt->inferredType = $return_types;
            }
        }

        return $this->_checkMethodParams($stmt->args, $method_id, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected static function _fleshOutReturnTypes(Type\Union $return_type, array $args, $method_id)
    {
        foreach ($return_type->types as $return_type_part) {
            $return_type_part = self::_fleshOutAtomicReturnType($return_type_part, $args, $method_id);
        }

        return $return_type;
    }

    protected static function _fleshOutAtomicReturnType(Type\Atomic $return_type, array $args, $method_id)
    {
        if ($return_type->value === '$this' || $return_type->value === 'static') {
            $absolute_class = explode('::', $method_id)[0];

            $return_type->value = $absolute_class;
        }
        else if ($return_type->value[0] === '$') {
            $method_params = ClassMethodChecker::getMethodParams($method_id);

            foreach ($args as $i => $arg) {
                $method_param = $method_params[$i];

                if ($return_type->value === '$' . $method_param['name']) {
                    if ($arg->value instanceof PhpParser\Node\Scalar\String_) {
                        $return_type->value = preg_replace('/^\\\/', '', $arg->value->value);
                    }
                }
            }

            if ($return_type->value[0] === '$') {
                $return_type = Type::getMixed();
            }
        }

        if ($return_type instanceof GenericType) {
            foreach ($return_type->type_params as $type_param) {
                if ($type_param instanceof Type\Union) {
                    $type_param = self::_fleshOutReturnTypes($type_param, $args, $method_id);
                }
                else {
                    $type_param = self::_fleshOutAtomicReturnType($type_param, $args, $method_id);
                }
            }
        }
    }

    protected static function _getMethodFromCallBlock($call, array $args, $method_id)
    {
        $absolute_class = explode('::', $method_id)[0];

        $original_call = $call;

        $call = preg_replace('/^\$this(->|::)/', $absolute_class . '::', $call);

        $call = preg_replace('/\(\)$/', '', $call);

        if (strpos($call, '$') !== false) {
            $method_params = ClassMethodChecker::getMethodParams($method_id);

            foreach ($args as $i => $arg) {
                $method_param = $method_params[$i];
                $preg_var_name = preg_quote('$' . $method_param['name']);

                if (preg_match('/::' . $preg_var_name . '$/', $call)) {
                    if ($arg->value instanceof PhpParser\Node\Scalar\String_) {
                        $call = preg_replace('/' . $preg_var_name . '$/', $arg->value->value, $call);
                        break;
                    }
                }
            }
        }

        return $original_call === $call || strpos($call, '$') !== false ? null : $call;
    }

    protected function _checkMethodParams(array $args, $method_id, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        foreach ($args as $i => $arg) {
            if ($arg->value instanceof PhpParser\Node\Expr\PropertyFetch &&
                $arg->value->var instanceof PhpParser\Node\Expr\Variable &&
                $arg->value->var->name === 'this' &&
                is_string($arg->value->name)
            ) {
                $property_id = 'this' . '->' . $arg->value->name;

                if ($method_id) {
                    if (isset($vars_in_scope[$property_id]) && !$vars_in_scope[$property_id]->isMixed()) {
                        if ($this->_checkFunctionArgumentType($vars_in_scope[$property_id], $method_id, $i, $this->_file_name, $arg->getLine()) === false) {
                            return false;
                        }
                    }

                    if ($this->_isPassedByReference($method_id, $i)) {
                        $this->_assignByRefParam($arg->value, $method_id, $vars_in_scope, $vars_possibly_in_scope);
                    }
                    else {
                        if ($this->_checkPropertyFetch($arg->value, $vars_in_scope, $vars_possibly_in_scope) === false) {
                            return false;
                        }
                    }
                } else {

                    if (false || !isset($vars_in_scope[$property_id]) || $vars_in_scope[$property_id]->isNull()) {
                        // we don't know if it exists, assume it's passed by reference
                        $vars_in_scope[$property_id] = Type::getMixed();
                        $vars_possibly_in_scope[$property_id] = true;
                        $this->registerVariable($property_id, $arg->value->getLine());
                    }

                }
            }
            elseif ($arg->value instanceof PhpParser\Node\Expr\Variable) {
                if ($method_id) {
                    if ($this->_checkVariable($arg->value, $vars_in_scope, $vars_possibly_in_scope, $method_id, $i) === false) {
                        return false;
                    }

                } elseif (is_string($arg->value->name)) {
                    if (false || !isset($vars_in_scope[$arg->value->name]) || $vars_in_scope[$arg->value->name]->isNull()) {
                        // we don't know if it exists, assume it's passed by reference
                        $vars_in_scope[$arg->value->name] = Type::getMixed();
                        $vars_possibly_in_scope[$arg->value->name] = true;
                        $this->registerVariable($arg->value->name, $arg->value->getLine());
                    }
                }
            } else {
                if ($this->_checkExpression($arg->value, $vars_in_scope, $vars_possibly_in_scope) === false) {
                    return false;
                }
            }

            if ($method_id && isset($arg->value->inferredType)) {
                if ($this->_checkFunctionArgumentType($arg->value->inferredType, $method_id, $i, $this->_file_name, $arg->value->getLine()) === false) {
                    return false;
                }
            }
        }
    }

    protected function _checkConstFetch(PhpParser\Node\Expr\ConstFetch $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name) {
            switch ($stmt->name->parts) {
                case ['null']:
                    $stmt->inferredType = Type::getNull();
                    break;

                case ['false']:
                    // false is a subtype of bool
                    $stmt->inferredType = Type::getFalse();
                    break;

                case ['true']:
                    $stmt->inferredType = Type::getBool();
                    break;
            }
        }
    }

    protected function _checkClassConstFetch(PhpParser\Node\Expr\ClassConstFetch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($this->_check_consts && $stmt->class instanceof PhpParser\Node\Name && $stmt->class->parts !== ['static']) {
            if ($stmt->class->parts === ['self']) {
                $absolute_class = $this->_absolute_class;
            } else {
                $absolute_class = ClassChecker::getAbsoluteClassFromName($stmt->class, $this->_namespace, $this->_aliased_classes);
                if (ClassChecker::checkAbsoluteClass($absolute_class, $stmt, $this->_file_name) === false) {
                    return false;
                }
            }

            $const_id = $absolute_class . '::' . $stmt->name;

            if (!defined($const_id)) {
                if (ExceptionHandler::accepts(
                    new UndefinedConstant('Const ' . $const_id . ' is not defined', $this->_file_name, $stmt->getLine())
                )) {
                    return false;
                }
            }

            return;
        }

        if ($stmt->class instanceof PhpParser\Node\Expr) {
            if ($this->_checkExpression($stmt->class, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
        }
    }

    /**
     * @return null|false
     */
    protected function _checkStaticPropertyFetch(PhpParser\Node\Expr\StaticPropertyFetch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($stmt->class instanceof PhpParser\Node\Expr\Variable || $stmt->class instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            // this is when calling $some_class::staticMethod() - which is a shitty way of doing things
            // because it can't be statically type-checked
            return;
        }

        $method_id = null;
        $absolute_class = null;

        if (count($stmt->class->parts) === 1 && in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
            if ($stmt->class->parts[0] === 'parent') {
                $absolute_class = $this->_class_extends;
            } else {
                $absolute_class = ($this->_namespace ? $this->_namespace . '\\' : '') . $this->_class_name;
            }
        } elseif ($this->_check_classes) {
            if (ClassChecker::checkClassName($stmt->class, $this->_namespace, $this->_aliased_classes, $this->_file_name) === false) {
                return false;
            }
            $absolute_class = ClassChecker::getAbsoluteClassFromName($stmt->class, $this->_namespace, $this->_aliased_classes);
        }

        if ($absolute_class && $this->_check_variables && is_string($stmt->name) && !self::isMock($absolute_class)) {
            $var_id = $absolute_class . '::$' . $stmt->name;

            if (!self::_staticVarExists($var_id)) {
                if (ExceptionHandler::accepts(
                    new UndefinedVariable('Static variable ' . $var_id . ' does not exist', $this->_file_name, $stmt->getLine())
                )) {
                    return false;
                }
            }
        }
    }

    protected function _checkReturn(PhpParser\Node\Stmt\Return_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $type_in_comments = null;
        $type_in_comments_var_id = null;
        $doc_comment = $stmt->getDocComment();

        if ($doc_comment) {
            $comments = self::parseDocComment($doc_comment);

            if ($comments && isset($comments['specials']['var'][0])) {
                $var_parts = array_filter(preg_split('/[\s\t]+/', $comments['specials']['var'][0]));

                if ($var_parts) {
                    $type_in_comments = $var_parts[0];

                    if ($type_in_comments[0] === strtoupper($type_in_comments[0])) {
                        $type_in_comments = ClassChecker::getAbsoluteClassFromString($type_in_comments, $this->_namespace, $this->_aliased_classes);
                    }

                    // support PHPStorm-style docblocks like
                    // @var Type $variable
                    if (count($var_parts) > 1 && $var_parts[1][0] === '$') {
                        $type_in_comments_var_id = substr($var_parts[1], 1);
                    }
                }
            }
        }

        if ($type_in_comments_var_id) {
            if (isset($vars_in_scope[$type_in_comments_var_id])) {
                $vars_in_scope[$type_in_comments_var_id] = Type::parseString($type_in_comments);
            }

            $type_in_comments = null;
        }

        if ($stmt->expr) {
            if ($this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }

            if ($type_in_comments) {
                $stmt->inferredType = Type::parseString($type_in_comments);
            }
            elseif (isset($stmt->expr->inferredType)) {
                $stmt->inferredType = $stmt->expr->inferredType;
            }
            else {
                $stmt->inferredType = Type::getMixed();
            }
        }
        else {
            $stmt->inferredType = Type::getVoid();
        }

        if ($this->_source instanceof FunctionChecker) {
            $this->_source->addReturnTypes($stmt->expr ? (string) $stmt->inferredType : '', $vars_in_scope, $vars_possibly_in_scope);
        }
    }

    protected function _checkTernary(PhpParser\Node\Expr\Ternary $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($this->_checkCondition($stmt->cond, $vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }

        $if_types = $this->_type_checker->getTypeAssertions($stmt->cond, true);

        $can_negate_if_types = !($stmt->cond instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd);

        if ($stmt->if) {
            $t_if_vars_in_scope = TypeChecker::reconcileKeyedTypes($if_types, $vars_in_scope, $this->_file_name, $stmt->getLine());

            if ($t_if_vars_in_scope === false) {
                return false;
            }

            if ($this->_checkExpression($stmt->if, $t_if_vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
        }

        if ($can_negate_if_types) {
            $negated_if_types = TypeChecker::negateTypes($if_types);
            $t_else_vars_in_scope = TypeChecker::reconcileKeyedTypes($negated_if_types, $vars_in_scope, $this->_file_name, $stmt->getLine());

            if ($t_else_vars_in_scope === false) {
                return false;
            }
        }
        else {
            $t_else_vars_in_scope = $vars_in_scope;
        }

        if ($this->_checkExpression($stmt->else, $t_else_vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }

        $lhs_type = null;

        if ($stmt->if) {
            if (isset($stmt->if->inferredType)) {
                $lhs_type = $stmt->if->inferredType;
            }
        }
        elseif ($stmt->cond) {
            if (isset($stmt->cond->inferredType)) {
                $lhs_type = $stmt->cond->inferredType;
            }
        }

        if (!$lhs_type || !isset($stmt->else->inferredType)) {
            $stmt->inferredType = Type::getMixed();
        }
        else {
            $stmt->inferredType = Type::combineUnionTypes($lhs_type, $stmt->else->inferredType);
        }
    }

    protected function _checkBooleanNot(PhpParser\Node\Expr\BooleanNot $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        return $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkEmpty(PhpParser\Node\Expr\Empty_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        return $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkThrow(PhpParser\Node\Stmt\Throw_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        return $this->_checkExpression($stmt->expr, $vars_in_scope, $vars_possibly_in_scope);
    }

    protected function _checkSwitch(PhpParser\Node\Stmt\Switch_ $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope, array &$for_vars_possibly_in_scope)
    {
        $type_candidate_var = null;

        if ($stmt->cond instanceof PhpParser\Node\Expr\FuncCall &&
            $stmt->cond->name instanceof PhpParser\Node\Name &&
            $stmt->cond->name->parts === ['get_class']) {

            $var = $stmt->cond->args[0]->value;

            if ($var instanceof PhpParser\Node\Expr\Variable && is_string($var->name)) {
                $type_candidate_var = $var->name;
            }
        }

        if ($this->_checkCondition($stmt->cond, $vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }

        $case_types = [];

        $new_vars_in_scope = null;
        $new_vars_possibly_in_scope = [];

        $redefined_vars = null;

        foreach ($stmt->cases as $case) {
            if ($case->cond) {
                if ($this->_checkCondition($case->cond, $vars_in_scope, $vars_possibly_in_scope) === false) {
                    return false;
                }

                if ($type_candidate_var && $case->cond instanceof PhpParser\Node\Scalar\String_) {
                    $case_types[] = $case->cond->value;
                }
            }

            $last_stmt = null;

            if ($case->stmts) {
                $switch_vars = $type_candidate_var && !empty($case_types)
                                ? [$type_candidate_var => Type::parseString(implode('|', $case_types))]
                                : [];

                $case_vars_in_scope = array_merge($vars_in_scope, $switch_vars);
                $old_case_vars = $case_vars_in_scope;
                $case_vars_possibly_in_scope = array_merge($vars_possibly_in_scope, $switch_vars);

                $this->check($case->stmts, $case_vars_in_scope, $case_vars_possibly_in_scope);

                $last_stmt = $case->stmts[count($case->stmts) - 1];

                // has a return/throw at end
                $has_ending_statments = ScopeChecker::doesLeaveBlock($case->stmts, false, false);

                if (!$has_ending_statments) {
                    $vars = array_diff_key($case_vars_possibly_in_scope, $vars_possibly_in_scope);

                    $has_leaving_statements = ScopeChecker::doesLeaveBlock($case->stmts, true, false);

                    // if we're leaving this block, add vars to outer for loop scope
                    if ($has_leaving_statements) {
                        $for_vars_possibly_in_scope = array_merge($vars, $for_vars_possibly_in_scope);
                    }
                    else {
                        $case_redefined_vars = [];

                        foreach ($old_case_vars as $case_var => $type) {
                            if ($case_vars_in_scope[$case_var] !== $type) {
                                $case_redefined_vars[$case_var] = $case_vars_in_scope[$case_var];
                            }
                        }

                        if ($redefined_vars === null) {
                            $redefined_vars = $case_redefined_vars;
                        }
                        else {
                            foreach ($redefined_vars as $redefined_var => $type) {
                                if (!isset($case_redefined_vars[$redefined_var])) {
                                    unset($redefined_vars[$redefined_var]);
                                }
                            }
                        }

                        if ($new_vars_in_scope === null) {
                            $new_vars_in_scope = array_diff_key($case_vars_in_scope, $vars_in_scope);
                            $new_vars_possibly_in_scope = array_diff_key($case_vars_possibly_in_scope, $vars_possibly_in_scope);
                        }
                        else {
                            foreach ($new_vars_in_scope as $new_var => $type) {
                                if (!isset($case_vars_in_scope[$new_var])) {
                                    unset($new_vars_in_scope[$new_var]);
                                }
                            }

                            $new_vars_possibly_in_scope = array_merge(
                                array_diff_key(
                                    $case_vars_possibly_in_scope,
                                    $vars_possibly_in_scope
                                ),
                                $new_vars_possibly_in_scope
                            );
                        }
                    }
                }
            }

            if ($type_candidate_var && ($last_stmt instanceof PhpParser\Node\Stmt\Break_ || $last_stmt instanceof PhpParser\Node\Stmt\Return_)) {
                $case_types = [];
            }

            // only update vars if there is a default
            // if that default has a throw/return/continue, that should be handled above
            if ($case->cond === null) {
                if ($new_vars_in_scope) {
                    $vars_in_scope = array_merge($vars_in_scope, $new_vars_in_scope);
                }

                if ($redefined_vars) {
                    $vars_in_scope = array_merge($vars_in_scope, $redefined_vars);
                }
            }
        }

        $vars_possibly_in_scope = array_merge($vars_possibly_in_scope, $new_vars_possibly_in_scope);
    }

    protected function _checkFunctionArgumentType(Type\Union $input_type, $method_id, $argument_offset, $file_name, $line_number)
    {
        if (strpos($method_id, '::') !== false) {
            $method_params = ClassMethodChecker::getMethodParams($method_id);

            if (isset($method_params[$argument_offset])) {
                $param_type = $method_params[$argument_offset]['type'];

                if ($param_type->isMixed()) {
                    return;
                }

                if ($input_type->isMixed()) {
                    // @todo make this a config
                    return;
                }

                if ($param_type->isNullable() && !$param_type->isNullable()) {
                    if (ExceptionHandler::accepts(
                        new NullReference(
                            'Argument ' . ($argument_offset + 1) . ' of ' . $method_id . ' cannot be null, possibly null value provided',
                            $file_name,
                            $line_number
                        )
                    )) {
                        return false;
                    }
                }

                foreach ($input_type->types as $input_type_part) {
                    if ($input_type_part->isNull()) {
                        continue;
                    }

                    foreach ($param_type->types as $param_type_part) {
                        if ($param_type_part->isNull()) {
                            continue;
                        }

                        if ($input_type_part->value !== $param_type_part->value && !is_subclass_of($input_type_part->value, $param_type_part->value) && !self::isMock($input_type_part->value)) {
                            if (is_subclass_of($param_type_part->value, $input_type_part->value)) {
                                // @todo handle coercion
                                return;
                            }

                            if (ExceptionHandler::accepts(
                                new InvalidArgument(
                                    'Argument ' . ($argument_offset + 1) . ' expects ' . $param_type . ', ' . $input_type . ' provided',
                                    $file_name,
                                    $line_number
                                )
                            )) {
                                return false;
                            }
                        }
                    }
                }
            }
        }
    }

    protected function _checkFunctionCall(PhpParser\Node\Expr\FuncCall $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        $method = $stmt->name;

        if ($method instanceof PhpParser\Node\Name) {
            if ($method->parts === ['method_exists']) {
                $this->_check_methods = false;

            } elseif ($method->parts === ['function_exists']) {
                $this->_check_functions = false;

            } elseif ($method->parts === ['defined']) {
                $this->_check_consts = false;

            } elseif ($method->parts === ['extract']) {
                $this->_check_variables = false;

            } elseif ($method->parts === ['var_dump'] || $method->parts === ['die'] || $method->parts === ['exit']) {
                if (ExceptionHandler::accepts(
                    new ForbiddenCode('Unsafe ' . implode('', $method->parts), $this->_file_name, $stmt->getLine())
                )) {
                    return false;
                }
            }
        }

        $method_id = null;

        if ($stmt->name instanceof PhpParser\Node\Name && $this->_check_functions) {
            $method_id = implode('', $stmt->name->parts);

            if ($this->_absolute_class) {
                //$method_id = $this->_absolute_class . '::' . $method_id;
            }

            if ($this->_checkFunctionExists($method_id, $stmt) === false) {
                return false;
            }

            $stmt->inferredType = Type::getMixed();
        }

        foreach ($stmt->args as $i => $arg) {
            if ($arg->value instanceof PhpParser\Node\Expr\Variable) {
                if ($method_id) {
                    if ($this->_checkVariable($arg->value, $vars_in_scope, $vars_possibly_in_scope, $method_id, $i) === false) {
                        return false;
                    }
                } else {
                    if ($this->_checkVariable($arg->value, $vars_in_scope, $vars_possibly_in_scope) === false) {
                        return false;
                    }
                }
            } else {
                if ($this->_checkExpression($arg->value, $vars_in_scope, $vars_possibly_in_scope) === false) {
                    return false;
                }
            }
        }
    }

    protected function _checkArrayAccess(PhpParser\Node\Expr\ArrayDimFetch $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        if ($this->_checkExpression($stmt->var, $vars_in_scope, $vars_possibly_in_scope) === false) {
            return false;
        }
        if ($stmt->dim) {
            if ($this->_checkExpression($stmt->dim, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
        }
    }

    protected function _checkEncapsulatedString(PhpParser\Node\Scalar\Encapsed $stmt, array &$vars_in_scope, array &$vars_possibly_in_scope)
    {
        foreach ($stmt->parts as $part) {
            if ($this->_checkExpression($part, $vars_in_scope, $vars_possibly_in_scope) === false) {
                return false;
            }
        }
    }

    public function registerVariable($var_name, $line_number)
    {
        if (!isset($this->_all_vars[$var_name])) {
            $this->_all_vars[$var_name] = $line_number;
        }
    }

    public static function _getClassProperties(\ReflectionClass $reflection_class, $absolute_class_name)
    {
        $properties = $reflection_class->getProperties();
        $props_arr = [];

        foreach ($properties as $reflection_property) {
            if ($reflection_property->isPrivate() || $reflection_property->isStatic()) {
                continue;
            }

            self::$_existing_properties[$absolute_class_name . '::' . $reflection_property->getName()] = 1;
        }

        $parent_reflection_class = $reflection_class->getParentClass();

        if ($parent_reflection_class) {
            self::_getClassProperties($parent_reflection_class, $absolute_class_name);
        }
    }

    protected static function _propertyExists($property_id)
    {
        if (isset(self::$_existing_properties[$property_id])) {
            return true;
        }

        $absolute_class = explode('::', $property_id)[0];

        $reflection_class = new \ReflectionClass($absolute_class);

        self::_getClassProperties($reflection_class, $absolute_class);

        return isset(self::$_existing_properties[$property_id]);
    }

    /**
     * @return false|null
     */
    public function _checkFunctionExists($method_id, $stmt)
    {
        if (isset(self::$_existing_functions[$method_id])) {
            return;
        }

        $file_checker = FileChecker::getFileCheckerFromFileName($this->_file_name);

        if ($file_checker->hasFunction($method_id)) {
            return;
        }

        if (strpos($method_id, '::') !== false) {
            $method_id = preg_replace('/^[^:]+::/', '', $method_id);
        }

        try {
            (new \ReflectionFunction($method_id));
        }
        catch (\ReflectionException $e) {
            if (ExceptionHandler::accepts(
                new UndefinedFunction('Function ' . $method_id . ' does not exist', $this->_file_name, $stmt->getLine())
            )) {
                return false;
            }
        }

        self::$_existing_functions[$method_id] = 1;
    }

    protected static function _staticVarExists($var_id)
    {
        if (isset(self::$_existing_static_vars[$var_id])) {
            return true;
        }

        $absolute_class = explode('::', $var_id)[0];

        try {
            $reflection_class = new \ReflectionClass($absolute_class);
        }
        catch (\ReflectionException $e) {
            return false;
        }

        $static_properties = $reflection_class->getStaticProperties();

        foreach ($static_properties as $property => $value) {
            self::$_existing_static_vars[$absolute_class . '::$' . $property] = 1;
        }

        return isset(self::$_existing_static_vars[$var_id]);
    }

    /**
     * Parse a docblock comment into its parts.
     *
     * Taken from advanced api docmaker
     * Which was taken from https://github.com/facebook/libphutil/blob/master/src/parser/docblock/PhutilDocblockParser.php
     *
     * @return array Array of the main comment and specials
     */
    public static function parseDocComment($docblock)
    {
        // Strip off comments.
        $docblock = trim($docblock);
        $docblock = preg_replace('@^/\*\*@', '', $docblock);
        $docblock = preg_replace('@\*/$@', '', $docblock);
        $docblock = preg_replace('@^\s*\*@m', '', $docblock);

        // Normalize multi-line @specials.
        $lines = explode("\n", $docblock);
        $last = false;
        foreach ($lines as $k => $line) {
            if (preg_match('/^\s?@\w/i', $line)) {
                $last = $k;
            } elseif (preg_match('/^\s*$/', $line)) {
                $last = false;
            } elseif ($last !== false) {
                $lines[$last] = rtrim($lines[$last]).' '.trim($line);
                unset($lines[$k]);
            }
        }
        $docblock = implode("\n", $lines);

        $special = array();

        // Parse @specials.
        $matches = null;
        $have_specials = preg_match_all('/^\s?@(\w+)\s*([^\n]*)/m', $docblock, $matches, PREG_SET_ORDER);
        if ($have_specials) {
            $docblock = preg_replace('/^\s?@(\w+)\s*([^\n]*)/m', '', $docblock);
            foreach ($matches as $match) {
                list($_, $type, $data) = $match;

                if (empty($special[$type])) {
                    $special[$type] = array();
                }

                $special[$type][] = $data;
            }
        }

        $docblock = str_replace("\t", '  ', $docblock);

        // Smush the whole docblock to the left edge.
        $min_indent = 80;
        $indent = 0;
        foreach (array_filter(explode("\n", $docblock)) as $line) {
            for ($ii = 0; $ii < strlen($line); $ii++) {
                if ($line[$ii] != ' ') {
                    break;
                }
                $indent++;
            }

            $min_indent = min($indent, $min_indent);
        }

        $docblock = preg_replace('/^' . str_repeat(' ', $min_indent) . '/m', '', $docblock);
        $docblock = rtrim($docblock);

        // Trim any empty lines off the front, but leave the indent level if there
        // is one.
        $docblock = preg_replace('/^\s*\n/', '', $docblock);

        return array('description' => $docblock, 'specials' => $special);
    }

    /**
     * @return string
     */
    public static function renderDocComment(array $parsed_doc_comment)
    {
        $doc_comment_text = '/**' . PHP_EOL;

        $description_lines = null;

        $trimmed_description = trim($parsed_doc_comment['description']);

        if (!empty($trimmed_description)) {
            $description_lines = explode(PHP_EOL, $parsed_doc_comment['description']);

            foreach ($description_lines as $line) {
                $doc_comment_text .= ' * ' . $line . PHP_EOL;
            }
        }

        if ($description_lines && $parsed_doc_comment['specials']) {
            $doc_comment_text .= ' *' . PHP_EOL;
        }

        if ($parsed_doc_comment['specials']) {
            $type_lengths = array_map('strlen', array_keys($parsed_doc_comment['specials']));
            $type_width = max($type_lengths) + 1;

            foreach ($parsed_doc_comment['specials'] as $type => $lines) {
                foreach ($lines as $line) {
                    $doc_comment_text .= ' * @' . str_pad($type, $type_width) . $line . PHP_EOL;
                }
            }
        }



        $doc_comment_text .= ' */';

        return $doc_comment_text;
    }

    protected function _isPassedByReference($method_id, $argument_offset)
    {
        if (strpos($method_id, '::') !== false) {
            try {
                $method_params = ClassMethodChecker::getMethodParams($method_id);

                return $argument_offset < count($method_params) && $method_params[$argument_offset]['by_ref'];
            }
            catch (\ReflectionException $e) {
                // we fall through to the functions below
            }
        }

        $file_checker = FileChecker::getFileCheckerFromFileName($this->_file_name);

        if ($file_checker->hasFunction($method_id)) {
            return $file_checker->isPassedByReference($method_id, $argument_offset);
        }

        if (strpos($method_id, '::') !== false) {
            $method_id = preg_replace('/^[^:]+::/', '', $method_id);
        }

        try {
            $reflection_parameters = (new \ReflectionFunction($method_id))->getParameters();

            // if value is passed by reference
            return $argument_offset < count($reflection_parameters) && $reflection_parameters[$argument_offset]->isPassedByReference();
        }
        catch (\ReflectionException $e) {
            return false;
        }
    }



    public static function customCheckString(callable $function)
    {
        self::$_check_string_fn = $function;
    }

    /**
     * @return string
     */
    public static function findEntryPoints($method_id)
    {
        $output = 'Entry points for ' . $method_id;
        if (empty(self::$_method_call_index[$method_id])) {
            list($absolute_class, $method_name) = explode('::', $method_id);

            $reflection_class = new \ReflectionClass($absolute_class);
            $parent_class = $reflection_class->getParentClass();

            if ($parent_class) {
                try {
                    $parent_class->getMethod($method_name);
                    $method_id = $parent_class->getName() . '::' . $method_name;
                    return $output . ' - NONE - it extends ' . $method_id . ' though';
                }
                catch (\ReflectionException $e) {
                    // do nothing
                }
            }

            return $output . ' - NONE';
        }

        $parents = self::$_method_call_index[$method_id];
        $ignore = [$method_id];
        $entry_points = [];

        while (!empty($parents)) {
            $parent_method_id = array_shift($parents);
            $ignore[] = $parent_method_id;
            $new_parents = self::_findParents($parent_method_id, $ignore);

            if ($new_parents === null) {
                $entry_points[] = $parent_method_id;
            }
            else {
                $parents = array_merge($parents, $new_parents);
            }
        }

        $entry_points = array_unique($entry_points);

        if (count($entry_points) > 20) {
            return $output . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', array_slice($entry_points, 0, 20)) . ' and more...';
        }

        return $output . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $entry_points);
    }

    protected static function _findParents($method_id, array $ignore)
    {
        if (empty(self::$_method_call_index[$method_id])) {
            return null;
        }

        return array_diff(array_unique(self::$_method_call_index[$method_id]), $ignore);
    }

    protected static function _getPathTo(PhpParser\Node\Expr $stmt, $file_name)
    {
        if ($file_name[0] !== '/') {
            $file_name = getcwd() . '/' . $file_name;
        }

        if ($stmt instanceof PhpParser\Node\Scalar\String_) {
            return $stmt->value;

        } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp\Concat) {
            $left_string = self::_getPathTo($stmt->left, $file_name);
            $right_string = self::_getPathTo($stmt->right, $file_name);

            if ($left_string && $right_string) {
                return $left_string . $right_string;
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall &&
            $stmt->name instanceof PhpParser\Node\Name &&
            $stmt->name->parts === ['dirname']) {

            if ($stmt->args) {
                $evaled_path = self::_getPathTo($stmt->args[0]->value, $file_name);

                if (!$evaled_path) {
                    return;
                }

                return dirname($evaled_path);
            }

        } elseif ($stmt instanceof PhpParser\Node\Expr\ConstFetch && $stmt->name instanceof PhpParser\Node\Name) {
            $const_name = implode('', $stmt->name->parts);

            if (defined($const_name)) {
                return constant($const_name);
            }

        } elseif ($stmt instanceof PhpParser\Node\Scalar\MagicConst\Dir) {
            return dirname($file_name);

        } elseif ($stmt instanceof PhpParser\Node\Scalar\MagicConst\File) {
            return $file_name;
        }

        return null;
    }

    /**
     * @return string
     */
    protected static function _resolveIncludePath($file_name, $current_directory)
    {
        $paths = PATH_SEPARATOR == ':' ?
            preg_split('#(?<!phar):#', get_include_path()) :
            explode(PATH_SEPARATOR, get_include_path());

        foreach ($paths as $prefix) {
            $ds = substr($prefix, -1) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR;

            if ($prefix === '.') {
                $prefix = $current_directory;
            }

            $file = $prefix . $ds . $file_name;

            if (file_exists($file)) {
                return $file;
            }
        }
    }

    public static function setMockInterfaces(array $classes)
    {
        self::$_mock_interfaces = $classes;
    }

    public static function isMock($absolute_class)
    {
        return in_array($absolute_class, Config::getInstance()->getMockClasses());
    }

    /**
     * @return bool
     */
    protected static function _containsBooleanOr(PhpParser\Node\Expr\BinaryOp $stmt)
    {
        // we only want to discount expressions where either the whole thing is an or
        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr) {
            return true;
        }

        // or both sides are ors
        if (($stmt->left instanceof PhpParser\Node\Expr\BinaryOp && $stmt->left instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr) &&
            ($stmt->right instanceof PhpParser\Node\Expr\BinaryOp && $stmt->left instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr)) {
            return true;
        }

        return false;
    }

    public function getAliasedClasses()
    {
        return $this->_aliased_classes;
    }

    public static function getThisAssignments($method_id, $include_constructor = false)
    {
        $absolute_class = explode('::', $method_id)[0];

        $this_assignments = [];

        if ($include_constructor && isset(self::$_this_assignments[$absolute_class . '::__construct'])) {
            $this_assignments = self::$_this_assignments[$absolute_class . '::__construct'];
        }

        if (isset(self::$_this_assignments[$method_id])) {
            $this_assignments = TypeChecker::combineKeyedTypes($this_assignments, self::$_this_assignments[$method_id]);
        }

        if (isset(self::$_this_calls[$method_id])) {
            foreach (self::$_this_calls[$method_id] as $call) {
                $call_assingments = self::getThisAssignments($absolute_class . '::' . $call);
                $this_assignments = TypeChecker::combineKeyedTypes($this_assignments, $call_assingments);
            }
        }

        return $this_assignments;
    }
}
