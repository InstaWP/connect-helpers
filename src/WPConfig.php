<?php
namespace InstaWP\Connect\Helpers;

class WPConfig extends \WPConfigTransformer {

    protected $config_data;
    protected $is_cli;
    protected $blacklisted = [
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_HOST',
        'DB_CHARSET',
        'DB_COLLATE',
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
        'ABSPATH',
        'WP_HOME',
        'WP_SITEURL',
        'WP_CACHE_KEY_SALT',
        'COOKIE_DOMAIN',
        'DOMAIN_CURRENT_SITE',
    ];

    // InstaCache (Valkey/Redis) object-cache config is platform-managed and must never be
    // round-tripped through the Config Manager: array-valued constants (WP_REDIS_PASSWORD =
    // [acl_user, acl_pass]; WP_REDIS_SERVERS/CLUSTER/SENTINEL/SHARDS/*_GROUPS) collapse to a
    // mangled string on save, breaking object-cache auth. A prefix match blacklists the whole
    // family (present and future) rather than an enumerated, quickly-stale list.
    protected $blacklisted_prefixes = [
        'WP_REDIS_',
    ];

    protected function is_blacklisted( $constant ) {
        if ( in_array( $constant, $this->blacklisted, true ) ) {
            return true;
        }
        foreach ( $this->blacklisted_prefixes as $prefix ) {
            if ( 0 === strpos( $constant, $prefix ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Control structures whose body we treat as a conditional scope.
     *
     * @return array
     */
    protected function scope_keywords() {
        $keywords = [ T_IF, T_ELSEIF, T_ELSE, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_DO, T_TRY, T_CATCH, T_FUNCTION ];

        foreach ( [ 'T_FINALLY', 'T_FN', 'T_MATCH' ] as $maybe ) {
            if ( defined( $maybe ) ) {
                $keywords[] = constant( $maybe );
            }
        }

        return $keywords;
    }

    /**
     * Control structures that support PHP's alternative (colon) syntax.
     *
     * Deliberately excludes T_FUNCTION so a return type (`function f(): void`) is not
     * mistaken for the start of a block.
     *
     * @return array
     */
    protected function alternative_syntax_keywords() {
        return [ T_IF, T_ELSEIF, T_ELSE, T_WHILE, T_FOR, T_FOREACH, T_SWITCH ];
    }

    /**
     * Names of constants whose define() does NOT sit at the top level of wp-config.php.
     *
     * WPConfigTransformer parses wp-config.php with a flat regex that has no awareness of
     * enclosing scope, so a define() nested in a block — for example the InstaCache
     * drop-in's
     *
     *     if ( defined( 'WP_CLI' ) && WP_CLI ) {
     *         define( 'WP_REDIS_DISABLED', true );
     *     }
     *
     * — is reported exactly like a top-level one. Surfacing such a constant to the Config
     * Manager is wrong twice over: it advertises a value that does not apply to ordinary
     * requests, and saving it back rewrites code inside a branch the caller never saw.
     *
     * A define() whose every enclosing condition names the constant itself — the ordinary
     * `if ( ! defined( 'FOO' ) ) { define( 'FOO', ... ); }` idempotency guard — is
     * unconditional in effect, so it stays manageable.
     *
     * Known limitation: a nested define() is only detected where the tokenizer is
     * available; without it nothing is filtered and behaviour is unchanged.
     *
     * @param string $src wp-config.php source.
     *
     * @return array Constant name => true.
     */
    protected function scoped_constants( $src ) {
        if ( ! function_exists( 'token_get_all' ) ) {
            return [];
        }

        $tokens = @token_get_all( $src );

        if ( empty( $tokens ) ) {
            return [];
        }

        $scope_keywords = $this->scope_keywords();
        $alt_keywords   = $this->alternative_syntax_keywords();
        $end_keywords   = [ T_ENDIF, T_ENDWHILE, T_ENDFOR, T_ENDFOREACH, T_ENDSWITCH ];

        $scoped  = [];
        $stack   = [];   // One entry per open block: the header text that introduced it.
        $header  = null; // Header text of the control structure currently being read.
        $keyword = null; // Which keyword started that header.
        $paren   = 0;    // Parenthesis depth, so a ternary ':' is not read as a block opener.
        $total   = count( $tokens );

        for ( $index = 0; $index < $total; $index++ ) {
            $token = $tokens[ $index ];

            if ( is_array( $token ) ) {
                $id   = $token[0];
                $text = $token[1];

                if ( in_array( $id, $end_keywords, true ) ) {
                    array_pop( $stack );
                    continue;
                }

                // A '{' that opens string interpolation ("{$a}", "${a}") is closed by a plain
                // '}', so it has to be balanced here or every later define() looks nested.
                if ( T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
                    $stack[] = '';
                    continue;
                }

                if ( in_array( $id, $scope_keywords, true ) ) {
                    $header  = $text;
                    $keyword = $id;
                    continue;
                }

                if ( T_STRING === $id && 0 === strcasecmp( $text, 'define' ) && $this->is_define_call( $tokens, $index ) ) {
                    // Inside a block, or a brace-less body such as `if ( ... ) define( ... );`.
                    if ( ! empty( $stack ) || null !== $header ) {
                        $enclosing = $stack;
                        if ( null !== $header ) {
                            $enclosing[] = $header;
                        }

                        $name = $this->define_name( $tokens, $index );

                        if ( '' !== $name && ! $this->guards_itself( $enclosing, $name ) ) {
                            $scoped[ $name ] = true;
                        }
                    }
                    continue;
                }

                if ( null !== $header ) {
                    $header .= $text;
                }

                continue;
            }

            if ( '(' === $token ) {
                $paren++;
            } elseif ( ')' === $token ) {
                $paren--;
            }

            if ( '{' === $token ) {
                $stack[] = ( null === $header ) ? '' : $header;
                $header  = null;
                $keyword = null;
                continue;
            }

            if ( '}' === $token ) {
                array_pop( $stack );
                continue;
            }

            if ( ':' === $token && null !== $header && 0 === $paren && in_array( $keyword, $alt_keywords, true ) ) {
                $stack[] = $header;
                $header  = null;
                $keyword = null;
                continue;
            }

            if ( ';' === $token && 0 === $paren ) {
                // The statement ended without opening a block (a brace-less body, or `do ... while;`).
                $header  = null;
                $keyword = null;
                continue;
            }

            if ( null !== $header ) {
                $header .= $token;
            }
        }

        return $scoped;
    }

    /**
     * Whether the `define` token at $index is a real function call and not a method
     * name (`$obj->define(...)`, `Foo::define(...)`) or a declaration.
     *
     * @param array $tokens
     * @param int   $index
     *
     * @return bool
     */
    protected function is_define_call( $tokens, $index ) {
        for ( $before = $index - 1; $before >= 0; $before-- ) {
            $token = $tokens[ $before ];

            if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
                continue;
            }

            if ( is_array( $token ) && in_array( $token[0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ], true ) ) {
                return false;
            }

            break;
        }

        for ( $after = $index + 1; $after < count( $tokens ); $after++ ) {
            $token = $tokens[ $after ];

            if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
                continue;
            }

            return '(' === $token;
        }

        return false;
    }

    /**
     * The constant name of the define() call whose `define` token sits at $index.
     *
     * @param array $tokens
     * @param int   $index
     *
     * @return string Empty when the name is not a plain string literal.
     */
    protected function define_name( $tokens, $index ) {
        $total = count( $tokens );

        for ( $next = $index + 1; $next < $total; $next++ ) {
            $token = $tokens[ $next ];

            if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
                continue;
            }

            if ( '(' === $token ) {
                continue;
            }

            if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
                return trim( $token[1], "'\"" );
            }

            return '';
        }

        return '';
    }

    /**
     * Whether every enclosing condition names the constant itself, i.e. the define() is
     * only wrapped in its own `if ( ! defined( 'FOO' ) )` guard and therefore applies
     * unconditionally.
     *
     * @param array  $headers
     * @param string $name
     *
     * @return bool
     */
    protected function guards_itself( $headers, $name ) {
        if ( empty( $headers ) ) {
            return true;
        }

        foreach ( $headers as $header ) {
            if ( ! preg_match( '/\b' . preg_quote( $name, '/' ) . '\b/', $header ) ) {
                return false;
            }
        }

        return true;
    }

    public function __construct( array $constants = [], $is_cli = false, $read_only = false ) {
        $file = ABSPATH . 'wp-config.php';
        if ( ! file_exists( $file ) ) {
            if ( @file_exists( dirname( ABSPATH ) . '/wp-config.php' ) ) {
                $file = dirname( ABSPATH ) . '/wp-config.php';
            }
        }

        parent::__construct( $file, $read_only );

        $this->config_data = $constants;
        $this->is_cli      = $is_cli;
    }

    public function get() {
        $wp_config_src = file_get_contents( $this->wp_config_path );

        if ( ! trim( $wp_config_src ) ) {
            throw new \Exception( 'Config file is empty.' );
        }

        $this->wp_config_src = $wp_config_src;
        $this->wp_configs    = $this->parse_wp_config( $this->wp_config_src );

        if ( ! isset( $this->wp_configs['constant'] ) ) {
            throw new \Exception( "Config type constant does not exist." );
        }

        $results = [
            'wp-config' => [],
        ];

        $scoped = $this->is_cli ? [] : $this->scoped_constants( $this->wp_config_src );

        foreach ( $this->wp_configs['constant'] as $constant => $data ) {
            if ( ! $this->is_cli && ( preg_match( '/[a-z]/', $constant ) || $this->is_blacklisted( $constant ) || isset( $scoped[ $constant ] ) ) ) {
                continue;
            }

            if ( ! empty( $this->config_data ) && ! in_array( $constant, $this->config_data, true ) ) {
                continue;
            }

            $value = trim( $data['value'], "'" );
            if ( filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) !== null ) {
                $value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
            } elseif ( filter_var( $value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE ) !== null ) {
                $value = intval( $value );
            }

            $results['wp-config'][ $constant ] = $value;
        }

        return $results;
    }

    public function set() {
        $args    = [
            'normalize' => true,
            'add'       => true,
        ];
        $content = file_get_contents( $this->wp_config_path );

        if ( ! trim( $content ) ) {
            throw new \Exception( 'Config file is empty.' );
        }

        if ( false === strpos( $content, "/* That's all, stop editing!" ) ) {
            preg_match( '@\$table_prefix = (.*);@', $content, $matches );
            $args['anchor']    = isset( $matches[0] ) ? $matches[0] : '';
            $args['placement'] = 'after';
        }

        $scoped = $this->is_cli ? [] : $this->scoped_constants( $content );

        foreach ( $this->config_data as $key => $value ) {
            if ( empty( $key ) ) {
                continue;
            }

            if ( ! $this->is_cli && ( preg_match( '/[a-z]/', $key ) || $this->is_blacklisted( $key ) || isset( $scoped[ $key ] ) ) ) {
                continue;
            }

            if ( is_array( $value ) ) {
                if ( ! array_key_exists( 'value', $value ) ) {
                    continue;
                }

                $params = [ 'separator', 'add' ];
                foreach ( $params as $param ) {
                    if ( array_key_exists( $param, $value ) ) {
                        $args[ $param ] = $value[ $param ];
                    }
                }
                $args['raw'] = array_key_exists( 'raw', $value ) ? $value['raw'] : true;
                $value       = $value['value'];
            } elseif ( is_bool( $value ) ) {
                $value       = $value ? 'true' : 'false';
                $args['raw'] = true;
            } elseif ( is_numeric( $value ) ) {
                $value       = strval( $value );
                $args['raw'] = true;
            } elseif ( in_array( $value, [ 'true', 'false' ] ) ) {
                $value       = strval( $value );
                $args['raw'] = true;
            } else {
                $value       = sanitize_text_field( wp_unslash( $value ) );
                $args['raw'] = false;
            }

            try {
                $this->update( 'constant', $key, $value, $args );
            } catch ( \Exception $e ) {
                throw new \Exception( $e->getMessage() );
            }
        }

        return [ 'success' => true ];
    }

    public function delete() {
        $constants = array_filter( $this->config_data );

        if ( empty( $constants ) ) {
            throw new \Exception( 'No constants provided!' );
        }

        $scoped = [];

        if ( ! $this->is_cli ) {
            $content = file_get_contents( $this->wp_config_path );
            $scoped  = $this->scoped_constants( $content );
        }

        foreach ( $constants as $constant ) {
            // Same protection get()/set() apply: the Config Manager never removes a
            // blacklisted (platform-managed) constant, nor one that only exists inside a
            // conditional block it was never able to show the caller.
            if ( ! $this->is_cli && ( $this->is_blacklisted( $constant ) || isset( $scoped[ $constant ] ) ) ) {
                continue;
            }

            try {
                $this->remove( 'constant', $constant );
            } catch ( \Exception $e ) {
                throw new \Exception( $e->getMessage() );
            }
        }

        return [ 'success' => true ];
    }
}