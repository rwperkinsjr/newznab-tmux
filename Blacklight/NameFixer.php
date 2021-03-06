<?php

namespace Blacklight;

use App\Models\Group;
use App\Models\Predb;
use Blacklight\db\DB;
use App\Models\Release;
use App\Models\Category;
use Illuminate\Support\Carbon;
use Blacklight\utility\Utility;
use Blacklight\processing\PostProcess;

/**
 * Class NameFixer.
 */
class NameFixer
{
    public const PREDB_REGEX = '/([\w\(\)]+[\s\._-]([\w\(\)]+[\s\._-])+[\w\(\)]+-\w+)/';

    // Constants for name fixing status
    public const PROC_NFO_NONE = 0;
    public const PROC_NFO_DONE = 1;
    public const PROC_FILES_NONE = 0;
    public const PROC_FILES_DONE = 1;
    public const PROC_PAR2_NONE = 0;
    public const PROC_PAR2_DONE = 1;
    public const PROC_UID_NONE = 0;
    public const PROC_UID_DONE = 1;
    public const PROC_HASH16K_NONE = 0;
    public const PROC_HASH16K_DONE = 1;
    public const PROC_SRR_NONE = 0;
    public const PROC_SRR_DONE = 1;

    // Constants for overall rename status
    public const IS_RENAMED_NONE = 0;
    public const IS_RENAMED_DONE = 1;

    /**
     * Has the current release found a new name?
     *
     * @var bool
     */
    public $matched;

    /**
     * How many releases have got a new name?
     *
     * @var int
     */
    public $fixed;

    /**
     * How many releases were checked.
     *
     * @var int
     */
    public $checked;

    /**
     * Whether or not the check has completed.
     *
     * @var bool
     */
    public $done;

    /**
     * Whether or not to echo info to CLI.
     *
     * @var bool
     */
    public $echooutput;

    /**
     * Total releases we are working on.
     *
     * @var int
     */
    protected $_totalReleases;

    /**
     * The cleaned filename we want to match.
     *
     * @var string
     */
    protected $_fileName;

    /**
     * The release ID we are trying to rename.
     *
     * @var int
     */
    protected $relid;

    /**
     * @var string
     */
    protected $othercats;

    /**
     * @var string
     */
    protected $timeother;

    /**
     * @var string
     */
    protected $timeall;

    /**
     * @var string
     */
    protected $fullother;

    /**
     * @var string
     */
    protected $fullall;

    /**
     * @var \Blacklight\db\DB
     */
    public $pdo;

    /**
     * @var \Blacklight\ConsoleTools
     */
    public $consoletools;

    /**
     * @var \Blacklight\Categorize
     */
    public $category;

    /**
     * @var \Blacklight\utility\Utility
     */
    public $text;

    /**
     * @var \Blacklight\SphinxSearch
     */
    public $sphinx;

    /**
     * @param array $options Class instances / Echo to cli.
     * @throws \Exception
     */
    public function __construct(array $options = [])
    {
        $defaults = [
            'Echo'         => true,
            'Categorize'   => null,
            'ConsoleTools' => null,
            'Groups'       => null,
            'Misc'         => null,
            'Settings'     => null,
            'SphinxSearch' => null,
        ];
        $options += $defaults;

        $this->echooutput = ($options['Echo'] && config('nntmux.echocli'));
        $this->relid = $this->fixed = $this->checked = 0;
        $this->pdo = ($options['Settings'] instanceof DB ? $options['Settings'] : new DB());
        $this->othercats = implode(',', Category::OTHERS_GROUP);
        $this->timeother = sprintf(' AND rel.adddate > (NOW() - INTERVAL 6 HOUR) AND rel.categories_id IN (%s) GROUP BY rel.id ORDER BY postdate DESC', $this->othercats);
        $this->timeall = ' AND rel.adddate > (NOW() - INTERVAL 6 HOUR) GROUP BY rel.id ORDER BY postdate DESC';
        $this->fullother = sprintf(' AND rel.categories_id IN (%s) GROUP BY rel.id', $this->othercats);
        $this->fullall = '';
        $this->_fileName = '';
        $this->done = $this->matched = false;
        $this->consoletools = ($options['ConsoleTools'] instanceof ConsoleTools ? $options['ConsoleTools'] : new ConsoleTools());
        $this->category = ($options['Categorize'] instanceof Categorize ? $options['Categorize'] : new Categorize(['Settings' => $this->pdo]));
        $this->text = ($options['Misc'] instanceof Utility ? $options['Misc'] : new Utility());
        $this->sphinx = ($options['SphinxSearch'] instanceof SphinxSearch ? $options['SphinxSearch'] : new SphinxSearch());
    }

    /**
     * Attempts to fix release names using the NFO.
     *
     *
     * @param $time
     * @param $echo
     * @param $cats
     * @param $nameStatus
     * @param $show
     * @throws \Exception
     */
    public function fixNamesWithNfo($time, $echo, $cats, $nameStatus, $show): void
    {
        $this->_echoStartMessage($time, '.nfo files');
        $type = 'NFO, ';

        // Only select releases we haven't checked here before
        $preId = false;
        if ($cats === 3) {
            $query = sprintf(
                '
					SELECT rel.id AS releases_id, rel.fromname
					FROM releases rel
					INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
					WHERE rel.nzbstatus = %d
					AND rel.predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
            $preId = true;
        } else {
            $query = sprintf(
                '
					SELECT rel.id AS releases_id, rel.fromname
					FROM releases rel
					INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
					WHERE (rel.isrenamed = %d OR rel.categories_id = %d)
					AND rel.predb_id = 0
					AND rel.proc_nfo = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                self::PROC_NFO_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);

        if ($releases instanceof \Traversable) {
            $total = $releases->rowCount();

            if ($total > 0) {
                $this->_totalReleases = $total;
                echo ColorCLI::primary(number_format($total).' releases to process.');

                foreach ($releases as $rel) {
                    $releaseRow = $this->pdo->queryOneRow(
                        sprintf(
                            '
							SELECT nfo.releases_id AS nfoid, rel.groups_id, rel.fromname, rel.categories_id, rel.name, rel.searchname,
								UNCOMPRESS(nfo) AS textstring, rel.id AS releases_id
							FROM releases rel
							INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
							WHERE rel.id = %d',
                            $rel['releases_id']
                        )
                    );

                    $this->checked++;

                    // Ignore encrypted NFOs.
                    if (preg_match('/^=newz\[NZB\]=\w+/', $releaseRow['textstring'])) {
                        $this->_updateSingleColumn('proc_nfo', self::PROC_NFO_DONE, $rel['releases_id']);
                        continue;
                    }

                    $this->reset();
                    $this->checkName($releaseRow, $echo, $type, $nameStatus, $show, $preId);
                    $this->_echoRenamed($show);
                }
                $this->_echoFoundCount($echo, ' NFO\'s');
            } else {
                echo ColorCLI::info('Nothing to fix.');
            }
        }
    }

    /**
     * Attempts to fix release names using the File name.
     *
     *
     * @param $time
     * @param $echo
     * @param $cats
     * @param $nameStatus
     * @param $show
     * @throws \Exception
     */
    public function fixNamesWithFiles($time, $echo, $cats, $nameStatus, $show): void
    {
        $this->_echoStartMessage($time, 'file names');
        $type = 'Filenames, ';

        $preId = false;
        if ($cats === 3) {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = rel.id)
					WHERE nzbstatus = %d
					AND predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
            $preId = true;
        } else {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = rel.id)
					WHERE (rel.isrenamed = %d OR rel.categories_id IN(%d, %d))
					AND rel.predb_id = 0
					AND proc_files = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_FILES_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);
        if ($releases instanceof \Traversable) {
            $total = $releases->rowCount();
            if ($total > 0) {
                $this->_totalReleases = $total;
                echo ColorCLI::primary(number_format($total).' file names to process.');

                foreach ($releases as $release) {
                    $this->reset();
                    $this->checkName($release, $echo, $type, $nameStatus, $show, $preId);
                    $this->checked++;
                    $this->_echoRenamed($show);
                }

                $this->_echoFoundCount($echo, ' files');
            } else {
                echo ColorCLI::info('Nothing to fix.');
            }
        }
    }

    /**
     * Attempts to fix XXX release names using the File name.
     *
     *
     * @param $time
     * @param $echo
     * @param $cats
     * @param $nameStatus
     * @param $show
     * @throws \Exception
     */
    public function fixXXXNamesWithFiles($time, $echo, $cats, $nameStatus, $show): void
    {
        $this->_echoStartMessage($time, 'file names');
        $type = 'Filenames, ';

        if ($cats === 3) {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = rel.id)
					WHERE nzbstatus = %d
					AND predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
        } else {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = rel.id)
					WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
					AND rel.predb_id = 0
					AND rf.name %s',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                $this->pdo->likeString('SDPORN')
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);
        if ($releases instanceof \Traversable) {
            $total = $releases->rowCount();
            if ($total > 0) {
                $this->_totalReleases = $total;
                echo ColorCLI::primary(number_format($total).' xxx file names to process.');

                foreach ($releases as $release) {
                    $this->reset();
                    $this->xxxNameCheck($release, $echo, $type, $nameStatus, $show);
                    $this->checked++;
                    $this->_echoRenamed($show);
                }
                $this->_echoFoundCount($echo, ' files');
            } else {
                echo ColorCLI::info('Nothing to fix.');
            }
        }
    }

    /**
     * Attempts to fix release names using the SRR filename.
     *
     *
     * @param $time
     * @param $echo
     * @param $cats
     * @param $nameStatus
     * @param $show
     * @throws \Exception
     */
    public function fixNamesWithSrr($time, $echo, $cats, $nameStatus, $show): void
    {
        $this->_echoStartMessage($time, 'SRR file names');
        $type = 'SRR, ';

        if ($cats === 3) {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = rel.id)
					WHERE nzbstatus = %d
					AND predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
        } else {
            $query = sprintf(
                '
					SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = rel.id)
					WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
					AND rel.predb_id = 0
					AND rf.name %s
					AND rel.proc_srr = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                $this->pdo->likeString('.srr', true, false),
                self::PROC_SRR_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);
        if ($releases instanceof \Traversable) {
            $total = $releases->rowCount();
            if ($total > 0) {
                $this->_totalReleases = $total;
                echo ColorCLI::primary(number_format($total).' srr file extensions to process.');

                foreach ($releases as $release) {
                    $this->reset();
                    $this->srrNameCheck($release, $echo, $type, $nameStatus, $show);
                    $this->checked++;
                    $this->_echoRenamed($show);
                }
                $this->_echoFoundCount($echo, ' files');
            } else {
                echo ColorCLI::info('Nothing to fix.');
            }
        }
    }

    /**
     * Attempts to fix release names using the Par2 File.
     *
     * @param int $time 1: 24 hours, 2: no time limit
     * @param int $echo 1: change the name, anything else: preview of what could have been changed.
     * @param int $cats 1: other categories, 2: all categories
     * @param      $nameStatus
     * @param      $show
     * @param NNTP $nntp
     * @throws \Exception
     */
    public function fixNamesWithPar2($time, $echo, $cats, $nameStatus, $show, $nntp): void
    {
        $this->_echoStartMessage($time, 'par2 files');

        if ($cats === 3) {
            $query = sprintf(
                '
					SELECT rel.id AS releases_id, rel.guid, rel.groups_id, rel.fromname
					FROM releases rel
					WHERE rel.nzbstatus = %d
					AND rel.predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
        } else {
            $query = sprintf(
                '
					SELECT rel.id AS releases_id, rel.guid, rel.groups_id, rel.fromname
					FROM releases rel
					WHERE rel.isrenamed = %d
					AND rel.predb_id = 0
					AND rel.proc_par2 = %d',
                self::IS_RENAMED_NONE,
                self::PROC_PAR2_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);

        if ($releases instanceof \Traversable) {
            $total = $releases->rowCount();
            if ($total > 0) {
                $this->_totalReleases = $total;

                echo ColorCLI::primary(number_format($total).' releases to process.');
                $Nfo = new Nfo();
                $nzbContents = new NZBContents(
                    [
                        'Echo'        => $this->echooutput,
                        'NNTP'        => $nntp,
                        'Nfo'         => $Nfo,
                        'Settings'    => $this->pdo,
                        'PostProcess' => new PostProcess(['Settings' => $this->pdo, 'Nfo' => $Nfo]),
                    ]
                );

                foreach ($releases as $release) {
                    if ($nzbContents->checkPAR2($release['guid'], $release['releases_id'], $release['groups_id'], $nameStatus, $show) === true) {
                        $this->fixed++;
                    }

                    $this->checked++;
                    $this->_echoRenamed($show);
                }
                $this->_echoFoundCount($echo, ' files');
            } else {
                echo ColorCLI::alternate('Nothing to fix.');
            }
        }
    }

    /**
     * Attempts to fix release names using the mediainfo xml Unique_ID.
     *
     *
     * @param $time
     * @param $echo
     * @param $cats
     * @param $nameStatus
     * @param $show
     * @throws \Exception
     */
    public function fixNamesWithMedia($time, $echo, $cats, $nameStatus, $show): void
    {
        $type = 'UID, ';

        $this->_echoStartMessage($time, 'mediainfo Unique_IDs');

        // Re-check all releases we haven't matched to a PreDB
        if ($cats === 3) {
            $query = sprintf(
                '
				SELECT
					rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
					rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
					HEX(ru.uniqueid) AS uid
				FROM releases rel
				LEFT JOIN release_unique ru ON ru.releases_id = rel.id
				WHERE ru.releases_id IS NOT NULL
				AND rel.nzbstatus = %d
				AND rel.predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
        // Otherwise check only releases we haven't renamed and checked uid before in Misc categories
        } else {
            $query = sprintf(
                '
				SELECT
					rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
					rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
					HEX(ru.uniqueid) AS uid
				FROM releases rel
				LEFT JOIN release_unique ru ON ru.releases_id = rel.id
				WHERE ru.releases_id IS NOT NULL
				AND rel.nzbstatus = %d
				AND rel.isrenamed = %d
				AND rel.predb_id = 0
				AND rel.categories_id IN (%d, %d)
				AND rel.proc_uid = %d',
                NZB::NZB_ADDED,
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_UID_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);
        if ($releases instanceof \Traversable) {
            $total = $releases->rowCount();
            if ($total > 0) {
                $this->_totalReleases = $total;
                echo ColorCLI::primary(number_format($total).' unique ids to process.');
                foreach ($releases as $rel) {
                    $this->checked++;
                    $this->reset();
                    $this->uidCheck($rel, $echo, $type, $nameStatus, $show);
                    $this->_echoRenamed($show);
                }
                $this->_echoFoundCount($echo, ' UID\'s');
            } else {
                echo ColorCLI::info('Nothing to fix.');
            }
        }
    }

    /**
     * @param $time
     * @param $echo
     * @param $cats
     * @param $nameStatus
     * @param $show
     * @throws \Exception
     */
    public function fixNamesWithMediaMovieName($time, $echo, $cats, $nameStatus, $show): void
    {
        $type = 'Mediainfo, ';

        $this->_echoStartMessage($time, 'Mediainfo movie_name');

        // Re-check all releases we haven't matched to a PreDB
        if ($cats === 3) {
            $query = sprintf(
                "
				SELECT rel.id AS releases_id, rel.name, rel.name AS textstring, rel.predb_id, rel.searchname, rel.fromname, rel.groups_id, rel.categories_id, rel.id AS releases_id, rf.mediainfo AS mediainfo
				FROM releases rel
				INNER JOIN releaseextrafull rf ON (rf.releases_id = rel.id)
				WHERE rel.name REGEXP '[a-z0-9]{32,64}'
                AND rf.mediainfo REGEXP '\<Movie_name\>'
                AND rel.nzbstatus = %d
                AND rel.predb_id = 0",
                NZB::NZB_ADDED
            );
            $cats = 2;
        // Otherwise check only releases we haven't renamed and checked uid before in Misc categories
        } else {
            $query = sprintf(
                "
				SELECT rel.id AS releases_id, rel.name, rel.name AS textstring, rel.predb_id, rel.searchname, rel.fromname, rel.groups_id, rel.categories_id, rel.id AS releases_id, rf.mediainfo AS mediainfo
				FROM releases rel
				INNER JOIN releaseextrafull rf ON (rf.releases_id = rel.id)
				WHERE rel.name REGEXP '[a-z0-9]{32,64}'
				AND rf.mediainfo REGEXP '\<Movie_name\>'
				AND rel.nzbstatus = %d
                AND rel.isrenamed = %d
                AND rel.predb_id = 0
				AND rel.categories_id IN (%d, %d)",
                NZB::NZB_ADDED,
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);
        if ($releases instanceof \Traversable) {
            $total = $releases->rowCount();
            if ($total > 0) {
                $this->_totalReleases = $total;
                echo ColorCLI::primary(number_format($total).' mediainfo movie names to process.');
                foreach ($releases as $rel) {
                    $this->checked++;
                    $this->reset();
                    $this->mediaMovieNameCheck($rel, $echo, $type, $nameStatus, $show);
                    $this->_echoRenamed($show);
                }
                $this->_echoFoundCount($echo, ' MediaInfo\'s');
            } else {
                echo ColorCLI::info('Nothing to fix.');
            }
        }
    }

    /**
     * Attempts to fix release names using the par2 hash_16K block.
     *
     *
     * @param $time
     * @param $echo
     * @param $cats
     * @param $nameStatus
     * @param $show
     * @throws \Exception
     */
    public function fixNamesWithParHash($time, $echo, $cats, $nameStatus, $show): void
    {
        $type = 'PAR2 hash, ';

        $this->_echoStartMessage($time, 'PAR2 hash_16K');

        // Re-check all releases we haven't matched to a PreDB
        if ($cats === 3) {
            $query = sprintf(
                '
				SELECT
					rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
					rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
					ph.hash AS hash
				FROM releases rel
				STRAIGHT_JOIN par_hashes ph ON ph.releases_id = rel.id
				AND rel.nzbstatus = %d
				AND rel.predb_id = 0',
                NZB::NZB_ADDED
            );
            $cats = 2;
        // Otherwise check only releases we haven't renamed and checked their par2 hash_16K before in Misc categories
        } else {
            $query = sprintf(
                '
				SELECT
					rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
					rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
					ph.hash AS hash
				FROM releases rel
				STRAIGHT_JOIN par_hashes ph ON ph.releases_id = rel.id
				AND rel.nzbstatus = %d
				AND rel.isrenamed = %d
				AND rel.categories_id IN (%d, %d)
				AND rel.predb_id = 0
				AND rel.proc_hash16k = %d',
                NZB::NZB_ADDED,
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_HASH16K_NONE
            );
        }

        $releases = $this->_getReleases($time, $cats, $query);

        if ($releases instanceof \Traversable) {
            $total = $releases->rowCount();
            if ($total > 0) {
                $this->_totalReleases = $total;
                echo ColorCLI::primary(number_format($total).' hash_16K to process.');
                foreach ($releases as $rel) {
                    $this->checked++;
                    $this->reset();
                    $this->hashCheck($rel, $echo, $type, $nameStatus, $show);
                    $this->_echoRenamed($show);
                }
                $this->_echoFoundCount($echo, ' hashes');
            } else {
                echo ColorCLI::info('Nothing to fix.');
            }
        }
    }

    /**
     * @param int $time 1: 24 hours, 2: no time limit
     * @param int $cats 1: other categories, 2: all categories
     * @param string $query Query to execute.
     *
     * @param string $limit limit defined by maxperrun
     *
     * @return bool|\PDOStatement False on failure, PDOStatement with query results on success.
     * @throws \RuntimeException
     */
    protected function _getReleases($time, $cats, $query, $limit = '')
    {
        $releases = false;
        $queryLimit = ($limit === '') ? '' : ' LIMIT '.$limit;
        // 24 hours, other cats
        if ($time === 1 && $cats === 1) {
            echo ColorCLI::header($query.$this->timeother.$queryLimit.";\n");
            $releases = $this->pdo->queryDirect($query.$this->timeother.$queryLimit);
        } // 24 hours, all cats
        if ($time === 1 && $cats === 2) {
            echo ColorCLI::header($query.$this->timeall.$queryLimit.";\n");
            $releases = $this->pdo->queryDirect($query.$this->timeall.$queryLimit);
        } //other cats
        if ($time === 2 && $cats === 1) {
            echo ColorCLI::header($query.$this->fullother.$queryLimit.";\n");
            $releases = $this->pdo->queryDirect($query.$this->fullother.$queryLimit);
        } // all cats
        if ($time === 2 && $cats === 2) {
            echo ColorCLI::header($query.$this->fullall.$queryLimit.";\n");
            $releases = $this->pdo->queryDirect($query.$this->fullall.$queryLimit);
        }

        return $releases;
    }

    /**
     * Echo the amount of releases that found a new name.
     *
     * @param int    $echo 1: change the name, anything else: preview of what could have been changed.
     * @param string $type The function type that found the name.
     */
    protected function _echoFoundCount($echo, $type): void
    {
        if ($echo === true) {
            echo ColorCLI::header(
                PHP_EOL.
                number_format($this->fixed).
                ' releases have had their names changed out of: '.
                number_format($this->checked).
                $type.'.'
            );
        } else {
            echo ColorCLI::header(
                PHP_EOL.
                number_format($this->fixed).
                ' releases could have their names changed. '.
                number_format($this->checked).
                $type.' were checked.'
            );
        }
    }

    /**
     * @param int    $time 1: 24 hours, 2: no time limit
     * @param string $type The function type.
     */
    protected function _echoStartMessage($time, $type): void
    {
        echo ColorCLI::header(
            sprintf(
                'Fixing search names %s using %s.',
                ($time === 1 ? 'in the past 6 hours' : 'since the beginning'),
                $type
            )
        );
    }

    /**
     * @param int $show
     */
    protected function _echoRenamed($show): void
    {
        if ($this->checked % 500 === 0 && $show === 1) {
            echo ColorCLI::alternate(PHP_EOL.number_format($this->checked).' files processed.'.PHP_EOL);
        }

        if ($show === 2) {
            $this->consoletools->overWritePrimary(
                'Renamed Releases: ['.
                number_format($this->fixed).
                '] '.
                $this->consoletools->percentString($this->checked, $this->_totalReleases)
            );
        }
    }

    /**
     * Update the release with the new information.
     *
     * @param array $release
     * @param string $name
     * @param string $method
     * @param bool $echo
     * @param string $type
     * @param int $nameStatus
     * @param int $show
     * @param int $preId
     * @throws \Exception
     */
    public function updateRelease($release, $name, $method, $echo, $type, int $nameStatus, int $show, int $preId = 0): void
    {
        $release['releases_id'] = $release['releases_id'] ?? $release['releaseid'];
        if ($this->relid !== (int) $release['releases_id']) {
            $releaseCleaning = new ReleaseCleaning($this->pdo);
            $newName = $releaseCleaning->fixerCleaner($name);
            if (strtolower($newName) !== strtolower($release['searchname'])) {
                $this->matched = true;
                $this->relid = (int) $release['releases_id'];

                $determinedCategory = $this->category->determineCategory($release['groups_id'], $newName, ! empty($release['fromname']) ? $release['fromname'] : '');

                if ($type === 'PAR2, ') {
                    $newName = ucwords($newName);
                    if (preg_match('/(.+?)\.[a-z0-9]{2,3}(PAR2)?$/i', $name, $match)) {
                        $newName = $match[1];
                    }
                }

                $this->fixed++;

                if (! empty($release['fromname']) && (preg_match('/oz@lot[.]com/i', $release['fromname']) || preg_match('/anon@y[.]com/i', $release['fromname']))) {
                    $newName = preg_replace('/(KTR|GUSH|BIUK|WEIRD)$/', 'SDCLiP', $newName);
                }
                $newName = explode('\\', $newName);
                $newName = preg_replace(['/^[-=_\.:\s]+/', '/[-=_\.:\s]+$/'], '', $newName[0]);

                if ($this->echooutput === true && $show === 1) {
                    $groupName = Group::getNameByID($release['groups_id']);
                    $oldCatName = Category::getNameByID($release['categories_id']);
                    $newCatName = Category::getNameByID($determinedCategory);

                    if ($type === 'PAR2, ') {
                        echo PHP_EOL;
                    }

                    ColorCLI::doEcho(
                        ColorCLI::headerOver('New name:  ').
                        ColorCLI::primary(substr($newName, 0, 299)).
                        ColorCLI::headerOver('Old name:  ').
                        ColorCLI::primary($release['searchname']).
                        ColorCLI::headerOver('Use name:  ').
                        ColorCLI::primary($release['name']).
                        ColorCLI::headerOver('New cat:   ').
                        ColorCLI::primary($newCatName).
                        ColorCLI::headerOver('Old cat:   ').
                        ColorCLI::primary($oldCatName).
                        ColorCLI::headerOver('Group:     ').
                        ColorCLI::primary($groupName).
                        ColorCLI::headerOver('Method:    ').
                        ColorCLI::primary($type.$method).
                        ColorCLI::headerOver('Releases ID: ').
                        ColorCLI::primary($release['releases_id']), true);
                    if (! empty($release['filename'])) {
                        ColorCLI::doEcho(
                            ColorCLI::headerOver('Filename:  ').
                            ColorCLI::primary($release['filename']), true);
                    }

                    if ($type !== 'PAR2, ') {
                        echo PHP_EOL;
                    }
                }

                $newTitle = $this->pdo->escapeString(substr($newName, 0, 299));

                if ($echo === true) {
                    if ($nameStatus === 1) {
                        $status = '';
                        switch ($type) {
                            case 'NFO, ':
                                $status = 'isrenamed = 1, iscategorized = 1, proc_nfo = 1,';
                                break;
                            case 'PAR2, ':
                                $status = 'isrenamed = 1, iscategorized = 1, proc_par2 = 1,';
                                break;
                            case 'Filenames, ':
                            case 'file matched source: ':
                                $status = 'isrenamed = 1, iscategorized = 1, proc_files = 1,';
                                break;
                            case 'SHA1, ':
                            case 'MD5, ':
                                $status = 'isrenamed = 1, iscategorized = 1, dehashstatus = 1,';
                                break;
                            case 'PreDB FT Exact, ':
                                $status = 'isrenamed = 1, iscategorized = 1,';
                                break;
                            case 'sorter, ':
                                $status = 'isrenamed = 1, iscategorized = 1, proc_sorter = 1,';
                                break;
                            case 'UID, ':
                                $status = 'isrenamed = 1, iscategorized = 1, proc_uid = 1,';
                                break;
                            case 'PAR2 hash, ':
                                $status = 'isrenamed = 1, iscategorized = 1, proc_hash16k = 1,';
                                break;
                            case 'SRR, ':
                                $status = 'isrenamed = 1, iscategorized = 1, proc_srr = 1,';
                                break;
                        }
                        $this->pdo->queryExec(
                            sprintf(
                                '
								UPDATE releases
								SET videos_id = 0, tv_episodes_id = 0, imdbid = NULL, musicinfo_id = NULL,
									consoleinfo_id = NULL, bookinfo_id = NULL, anidbid = NULL, predb_id = %d,
									searchname = %s, %s categories_id = %d
								WHERE id = %d',
                                $preId,
                                $newTitle,
                                $status,
                                $determinedCategory,
                                $release['releases_id']
                            )
                        );
                        $this->sphinx->updateRelease($release['releases_id']);
                    } else {
                        $newTitle = $this->pdo->escapeString(substr($newName, 0, 299));
                        $this->pdo->queryExec(
                            sprintf(
                                '
								UPDATE releases
								SET videos_id = 0, tv_episodes_id = 0, imdbid = NULL, musicinfo_id = NULL,
									consoleinfo_id = NULL, bookinfo_id = NULL, anidbid = NULL, predb_id = %d,
									searchname = %s, iscategorized = 1, categories_id = %d
								WHERE id = %d',
                                $preId,
                                $newTitle,
                                $determinedCategory,
                                $release['releases_id']
                            )
                        );
                        $this->sphinx->updateRelease($release['releases_id']);
                    }
                }
            }
        }
        $this->done = true;
    }

    /**
     * Echo a updated release name to CLI.
     *
     * @param array $data
     *              array(
     *              'new_name'     => (string) The new release search name.
     *              'old_name'     => (string) The old release search name.
     *              'new_category' => (string) The new category name or ID for the release.
     *              'old_category' => (string) The old category name or ID for the release.
     *              'group'        => (string) The group name or ID of the release.
     *              'release_id'   => (int)    The ID of the release.
     *              'method'       => (string) The method used to rename the release.
     *              )
     *
     * @static
     * @void
     */
    public static function echoChangedReleaseName(
        array $data =
        [
            'new_name'     => '',
            'old_name'     => '',
            'new_category' => '',
            'old_category' => '',
            'group'        => '',
            'releases_id'   => 0,
            'method'       => '',
        ]
    ): void {
        echo
            PHP_EOL.
            ColorCLI::headerOver('New name:     ').ColorCLI::primaryOver($data['new_name']).PHP_EOL.
            ColorCLI::headerOver('Old name:     ').ColorCLI::primaryOver($data['old_name']).PHP_EOL.
            ColorCLI::headerOver('New category: ').ColorCLI::primaryOver($data['new_category']).PHP_EOL.
            ColorCLI::headerOver('Old category: ').ColorCLI::primaryOver($data['old_category']).PHP_EOL.
            ColorCLI::headerOver('Group:        ').ColorCLI::primaryOver($data['group']).PHP_EOL.
            ColorCLI::headerOver('Releases ID:   ').ColorCLI::primaryOver($data['releases_id']).PHP_EOL.
            ColorCLI::headerOver('Method:       ').ColorCLI::primaryOver($data['method']).PHP_EOL;
    }

    /**
     * Match a PreDB title to a release name or searchname using an exact full-text match.
     *
     * @param $pre
     * @param $echo
     * @param $namestatus
     * @param $echooutput
     * @param $show
     *
     * @return int
     * @throws \Exception
     */
    public function matchPredbFT($pre, $echo, $namestatus, $echooutput, $show): int
    {
        $matching = $total = 0;

        $join = $this->_preFTsearchQuery($pre['title']);

        if ($join === '') {
            return $matching;
        }

        //Find release matches with fulltext and then identify exact matches with cleaned LIKE string
        $res = $this->pdo->queryDirect(
            sprintf(
                '
				SELECT r.id AS releases_id, r.name, r.searchname,
				r.fromname, r.groups_id, r.categories_id
				FROM releases r
				%1$s
				AND (r.name %2$s OR r.searchname %2$s)
				AND r.predb_id = 0
				LIMIT 21',
                $join,
                $this->pdo->likeString($pre['title'], true, true)
            )
        );

        if ($res !== false) {
            $total = $res->rowCount();
        }

        // Run if row count is positive, but do not run if row count exceeds 10 (as this is likely a failed title match)
        if ($total > 0 && $total <= 15 && $res instanceof \Traversable) {
            foreach ($res as $row) {
                if ($pre['title'] !== $row['searchname']) {
                    $this->updateRelease($row, $pre['title'], $method = 'Title Match source: '.$pre['source'], $echo, 'PreDB FT Exact, ', $namestatus, $show, $pre['predb_id']);
                    $matching++;
                } else {
                    $this->_updateSingleColumn('predb_id', $pre['predb_id'], $row['releases_id']);
                }
            }
        } elseif ($total >= 16) {
            $matching = -1;
        }

        return $matching;
    }

    /**
     * @param $preTitle
     *
     * @return string
     */
    protected function _preFTsearchQuery($preTitle): string
    {
        $join = '';

        if (\strlen($preTitle) >= 15 && preg_match(self::PREDB_REGEX, $preTitle)) {
            $titlematch = SphinxSearch::escapeString($preTitle);
            $join .= sprintf(
                        'INNER JOIN releases_se rse ON rse.id = r.id
						WHERE rse.query = "@(name,searchname,filename) %s;mode=extended"',
                        $titlematch
                    );
        }

        return $join;
    }

    /**
     * Retrieves releases and their file names to attempt PreDB matches
     * Runs in a limited mode based on arguments passed or a full mode broken into chunks of entire DB.
     *
     * @param array $args The CLI script arguments
     * @throws \Exception
     */
    public function getPreFileNames(array $args = []): void
    {
        $n = PHP_EOL;

        $show = (isset($args[2]) && $args[2] === 'show') ? 1 : 0;

        if (isset($args[1]) && is_numeric($args[1])) {
            $limit = 'LIMIT '.$args[1];
            $orderby = 'ORDER BY r.id DESC';
        } else {
            $orderby = 'ORDER BY r.id ASC';
            $limit = 'LIMIT 1000000';
        }

        ColorCLI::doEcho(ColorCLI::header(PHP_EOL.'Match PreFiles '.$args[1].' Started at '.Carbon::now()->format('Y-m-d')), true);
        ColorCLI::doEcho(ColorCLI::primary('Matching predb filename to cleaned release_files.name.'), true);

        $counter = $counted = 0;
        $timestart = time();

        $query = $this->pdo->queryDirect(
            sprintf(
                "
					SELECT r.id AS releases_id, r.name, r.searchname,
						r.fromname, r.groups_id, r.categories_id,
						GROUP_CONCAT(rf.name ORDER BY LENGTH(rf.name) DESC SEPARATOR '||') AS filename
					FROM releases r
					INNER JOIN release_files rf ON r.id = rf.releases_id
					AND rf.name IS NOT NULL
					WHERE r.predb_id = 0
					GROUP BY r.id
					%s %s",
                $orderby,
                $limit
            )
        );

        if ($query !== false) {
            $total = $query->rowCount();

            if ($total > 0 && $query instanceof \Traversable) {
                echo ColorCLI::header($n.number_format($total).' releases to process.');

                foreach ($query as $row) {
                    $success = $this->matchPredbFiles($row, true, 1, true, $show);
                    if ($success === 1) {
                        $counted++;
                    }
                    if ($show === 0) {
                        $this->consoletools->overWritePrimary('Renamed Releases: ['.number_format($counted).'] '.$this->consoletools->percentString(++$counter, $total));
                    }
                }
                echo ColorCLI::header($n.'Renamed '.number_format($counted).' releases in '.$this->consoletools->convertTime(time() - $timestart).'.');
            } else {
                echo ColorCLI::info($n.'Nothing to do.');
            }
        }
    }

    /**
     * Match a release filename to a PreDB filename or title.
     *
     * @param         $release
     * @param bool $echo
     * @param int $namestatus
     * @param bool $echooutput
     * @param int $show
     *
     * @return int
     * @throws \Exception
     */
    public function matchPredbFiles($release, $echo, $namestatus, $echooutput, $show): int
    {
        $matching = 0;
        $pre = false;

        foreach (explode('||', $release['filename']) as $key => $fileName) {
            $this->_fileName = $fileName;
            $this->_cleanMatchFiles();
            $preMatch = preg_match('/(\d{2}\.\d{2}\.\d{2})+[\w-.]+[\w]$/i', $this->_fileName, $match);
            if ($preMatch) {
                $result = Predb::search($match[0])->first();
                $preFTmatch = preg_match('/(\d{2}\.\d{2}\.\d{2})+[\w-.]+[\w]$/i', $result['filename'], $match1);
                if ($preFTmatch) {
                    if ($match[0] === $match1[0]) {
                        $this->_fileName = $result['filename'];
                    }
                }
            }

            if ($this->_fileName !== '') {
                $pre = Predb::query()
                    ->where('filename', $this->_fileName)
                    ->orWhere('title', $this->_fileName)
                    ->first(['id as predb_id', 'title', 'source']);
            }

            if ($pre !== null) {
                $release['filename'] = $this->_fileName;
                if ($pre['title'] !== $release['searchname']) {
                    $this->updateRelease($release, $pre['title'], $method = 'file matched source: '.$pre['source'], $echo, 'PreDB file match, ', $namestatus, $show, $pre['predb_id']);
                } else {
                    $this->_updateSingleColumn('predb_id', $pre['predb_id'], $release['releases_id']);
                }
                $matching++;
                break;
            }
        }

        return $matching;
    }

    /**
     * Cleans file names for PreDB Match.
     *
     *
     * @return string
     */
    protected function _cleanMatchFiles(): string
    {

        // first strip all non-printing chars  from filename
        $this->_fileName = Utility::stripNonPrintingChars($this->_fileName);

        if (\strlen($this->_fileName) > 0 && strpos($this->_fileName, '.') !== 0) {
            switch (true) {

                case strpos($this->_fileName, '.') !== false:
                    //some filenames start with a period that ends up creating bad matches so we don't process them
                    $this->_fileName = Utility::cutStringUsingLast('.', $this->_fileName, 'left', false);
                    continue;

                //if filename has a .part001, send it back to the function to cut the next period
                case preg_match('/\.part\d+$/', $this->_fileName):
                    $this->_fileName = Utility::cutStringUsingLast('.', $this->_fileName, 'left', false);
                    continue;

                //if filename has a .vol001, send it back to the function to cut the next period
                case preg_match('/\.vol\d+(\+\d+)?$/', $this->_fileName):
                    $this->_fileName = Utility::cutStringUsingLast('.', $this->_fileName, 'left', false);
                    continue;

                //if filename contains a slash, cut the string and keep string to the right of the last slash to remove dir
                case strpos($this->_fileName, '\\') !== false:
                    $this->_fileName = Utility::cutStringUsingLast('\\', $this->_fileName, 'right', false);
                    continue;

                // A lot of obscured releases have one NFO file properly named with a track number (Audio) at the front of it
                // This will strip out the track and match it to its pre title
                case preg_match('/^\d{2}-/', $this->_fileName):
                    $this->_fileName = preg_replace('/^\d{2}-/', '', $this->_fileName);
            }

            return trim($this->_fileName);
        }

        return false;
    }

    /**
     * Match a Hash from the predb to a release.
     *
     * @param string $hash
     * @param         $release
     * @param         $echo
     * @param         $namestatus
     * @param bool $echooutput
     * @param         $show
     *
     * @return int
     * @throws \Exception
     */
    public function matchPredbHash($hash, $release, $echo, $namestatus, $echooutput, $show): int
    {
        $matching = 0;
        $this->matched = false;

        // Determine MD5 or SHA1
        if (\strlen($hash) === 40) {
            $hashtype = 'SHA1, ';
        } else {
            $hashtype = 'MD5, ';
        }

        $row = $this->pdo->queryOneRow(
            sprintf(
                '
						SELECT p.id AS predb_id, p.title, p.source
						FROM predb p INNER JOIN predb_hashes h ON h.predb_id = p.id
						WHERE h.hash = UNHEX(%s)
						LIMIT 1',
                $this->pdo->escapeString($hash)
            )
        );

        if ($row !== false) {
            if ($row['title'] !== $release['searchname']) {
                $this->updateRelease($release, $row['title'], $method = 'predb hash release name: '.$row['source'], $echo, $hashtype, $namestatus, $show, $row['predb_id']);
                $matching++;
            }
        } else {
            $this->_updateSingleColumn('dehashstatus', $release['dehashstatus'] - 1, $release['releases_id']);
        }

        return $matching;
    }

    /**
     * Matches the hashes within the predb table to release files and subjects (names) which are hashed.
     *
     * @param $time
     * @param $echo
     * @param $cats
     * @param $namestatus
     * @param $show
     *
     * @return int
     * @throws \Exception
     */
    public function parseTitles($time, $echo, $cats, $namestatus, $show): int
    {
        $updated = $checked = 0;

        $tq = '';
        if ($time === 1) {
            $tq = 'AND r.adddate > (NOW() - INTERVAL 3 HOUR) ORDER BY rf.releases_id, rf.size DESC';
        }
        $ct = '';
        if ($cats === 1) {
            $ct = sprintf('AND r.categories_id IN (%s)', $this->othercats);
        }

        if ($this->echooutput) {
            $te = '';
            if ($time === 1) {
                $te = ' in the past 3 hours';
            }
            echo ColorCLI::header('Fixing search names'.$te.' using the predb hash.');
        }
        $regex = 'AND (r.ishashed = 1 OR rf.ishashed = 1)';

        if ($cats === 3) {
            $query = sprintf('SELECT r.id AS releases_id, r.name, r.searchname, r.categories_id, r.groups_id, '
                .'dehashstatus, rf.name AS filename FROM releases r '
                .'LEFT OUTER JOIN release_files rf ON r.id = rf.releases_id '
                .'WHERE nzbstatus = 1 AND dehashstatus BETWEEN -6 AND 0 AND predb_id = 0 %s', $regex);
        } else {
            $query = sprintf('SELECT r.id AS releases_id, r.name, r.searchname, r.categories_id, r.groups_id, '
                .'dehashstatus, rf.name AS filename FROM releases r '
                .'LEFT OUTER JOIN release_files rf ON r.id = rf.releases_id '
                .'WHERE nzbstatus = 1 AND isrenamed = 0 AND dehashstatus BETWEEN -6 AND 0 %s %s %s', $regex, $ct, $tq);
        }

        $res = $this->pdo->queryDirect($query);
        $total = $res->rowCount();
        echo ColorCLI::primary(number_format($total).' releases to process.');
        if ($res instanceof \Traversable) {
            foreach ($res as $row) {
                if (preg_match('/[a-fA-F0-9]{32,40}/i', $row['name'], $matches)) {
                    $updated += $this->matchPredbHash($matches[0], $row, $echo, $namestatus, $this->echooutput, $show);
                } elseif (preg_match('/[a-fA-F0-9]{32,40}/i', $row['filename'], $matches)) {
                    $updated += $this->matchPredbHash($matches[0], $row, $echo, $namestatus, $this->echooutput, $show);
                }
                if ($show === 2) {
                    ColorCLI::overWritePrimary('Renamed Releases: ['.number_format($updated).'] '.$this->consoletools->percentString($checked++, $total));
                }
            }
        }
        if ($echo === 1) {
            echo ColorCLI::header(PHP_EOL.$updated.' releases have had their names changed out of: '.number_format($checked).' files.');
        } else {
            echo ColorCLI::header(PHP_EOL.$updated.' releases could have their names changed. '.number_format($checked).' files were checked.');
        }

        return $updated;
    }

    /**
     * Check the array using regex for a clean name.
     *
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     * @param bool $preid
     *
     * @return bool
     * @throws \Exception
     */
    public function checkName($release, $echo, $type, $namestatus, $show, $preid = false): bool
    {
        // Get pre style name from releases.name
        if (preg_match_all(self::PREDB_REGEX, $release['textstring'], $matches) && ! preg_match('/Source\s\:/i', $release['textstring'])) {
            foreach ($matches as $match) {
                foreach ($match as $val) {
                    $title = Predb::query()->where('title', trim($val))->select(['title', 'id'])->first();
                    if ($title !== null) {
                        $this->updateRelease($release, $title['title'], $method = 'preDB: Match', $echo, $type, $namestatus, $show, $title['id']);
                        $preid = true;
                    }
                }
            }
        }

        // if only processing for PreDB match skip to return
        if ($preid !== true) {
            switch ($type) {
                case 'PAR2, ':
                    $this->fileCheck($release, $echo, $type, $namestatus, $show);
                    break;
                case 'PAR2 hash, ':
                    $this->hashCheck($release, $echo, $type, $namestatus, $show);
                    break;
                case 'UID, ':
                    $this->uidCheck($release, $echo, $type, $namestatus, $show);
                    break;
                case 'Mediainfo, ':
                    $this->mediaMovieNameCheck($release, $echo, $type, $namestatus, $show);
                    break;
                case 'SRR, ':
                    $this->srrNameCheck($release, $echo, $type, $namestatus, $show);
                    break;
                case 'NFO, ':
                    $this->nfoCheckTV($release, $echo, $type, $namestatus, $show);
                    $this->nfoCheckMov($release, $echo, $type, $namestatus, $show);
                    $this->nfoCheckMus($release, $echo, $type, $namestatus, $show);
                    $this->nfoCheckTY($release, $echo, $type, $namestatus, $show);
                    $this->nfoCheckG($release, $echo, $type, $namestatus, $show);
                    continue;
                case 'Filenames, ':
                    $this->fileCheck($release, $echo, $type, $namestatus, $show);
                    continue;
                default:
                    $this->tvCheck($release, $echo, $type, $namestatus, $show);
                    $this->movieCheck($release, $echo, $type, $namestatus, $show);
                    $this->gameCheck($release, $echo, $type, $namestatus, $show);
                    $this->appCheck($release, $echo, $type, $namestatus, $show);
            }

            // set NameFixer process flags after run
            if ($namestatus === 1 && $this->matched === false) {
                switch ($type) {
                    case 'NFO, ':
                        $this->_updateSingleColumn('proc_nfo', self::PROC_NFO_DONE, $release['releases_id']);
                        break;
                    case 'Filenames, ':
                        $this->_updateSingleColumn('proc_files', self::PROC_FILES_DONE, $release['releases_id']);
                        break;
                    case 'PAR2, ':
                        $this->_updateSingleColumn('proc_par2', self::PROC_PAR2_DONE, $release['releases_id']);
                        break;
                    case 'PAR2 hash, ':
                        $this->_updateSingleColumn('proc_hash16k', self::PROC_HASH16K_DONE, $release['releases_id']);
                        break;
                    case 'SRR, ':
                        $this->_updateSingleColumn('proc_srr', self::PROC_SRR_DONE, $release['releases_id']);
                        break;
                    case 'UID, ':
                        $this->_updateSingleColumn('proc_uid', self::PROC_UID_DONE, $release['releases_id']);
                        break;
                }
            }
        }

        return $this->matched;
    }

    /** This function updates a single variable column in releases
     *  The first parameter is the column to update, the second is the value
     *  The final parameter is the ID of the release to update.
     *
     * @param string  $column
     * @param int $status
     * @param int $id
     */
    public function _updateSingleColumn($column = '', $status = 0, $id = 0): void
    {
        if ((string) $column !== '' && (int) $id !== 0) {
            Release::query()->where('id', $id)->update([$column => $status]);
        }
    }

    /**
     * Look for a TV name.
     *
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     * @throws \Exception
     */
    public function tvCheck($release, $echo, $type, $namestatus, $show): void
    {
        $result = [];

        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            if (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2})[-\w.\',;.()]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -][-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'tvCheck: Title.SxxExx.Text.source.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[-\w.\',;& ]+((19|20)\d\d)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'tvCheck: Title.SxxExx.Text.year.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[-\w.\',;& ]+(480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'tvCheck: Title.SxxExx.Text.resolution.source.vcodec.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'tvCheck: Title.SxxExx.source.vcodec.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'tvCheck: Title.SxxExx.acodec.source.res.vcodec.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -]((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'tvCheck: Title.year.###(season/episode).source.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w(19|20)\d\d[._ -]\d{2}[._ -]\d{2}[._ -](IndyCar|NBA|NCW(T|Y)S|NNS|NSCS?)([._ -](19|20)\d\d)?[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'tvCheck: Sports', $echo, $type, $namestatus, $show);
            }
        }
    }

    /**
     * Look for a movie name.
     *
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     * @throws \Exception
     */
    public function movieCheck($release, $echo, $type, $namestatus, $show): void
    {
        $result = [];

        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            if (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[-\w.\',;& ]+(480|720|1080)[ip][._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.year.Text.res.vcod.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -](480|720|1080)[ip][-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.year.source.vcodec.res.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.year.source.vcodec.acodec.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+(Brazilian|Chinese|Croatian|Danish|Deutsch|Dutch|Estonian|English|Finnish|Flemish|Francais|French|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.year.language.acodec.source.vcodec.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.year.resolution.source.acodec.vcodec.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.year.resolution.source.vcodec.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.year.source.resolution.acodec.vcodec.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -](480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.year.resolution.acodec.vcodec.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/[-\w.\',;& ]+((19|20)\d\d)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BR(RIP)?|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](480|720|1080)[ip][._ -][-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.year.source.res.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+((19|20)\d\d)[._ -][-\w.\',;& ]+[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BR(RIP)?|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.year.eptitle.source.vcodec.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+(480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.resolution.source.acodec.vcodec.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+(480|720|1080)[ip][._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[-\w.\',;& ]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -]((19|20)\d\d)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.resolution.acodec.eptitle.source.year.group', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+(Brazilian|Chinese|Croatian|Danish|Deutsch|Dutch|Estonian|English|Finnish|Flemish|Francais|French|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)[._ -]((19|20)\d\d)[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?|Dolby( ?TrueHD)?|MP3|TrueHD)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'movieCheck: Title.language.year.acodec.src', $echo, $type, $namestatus, $show);
            }
        }
    }

    /**
     * Look for a game name.
     *
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     * @throws \Exception
     */
    public function gameCheck($release, $echo, $type, $namestatus, $show): void
    {
        $result = [];

        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            if (preg_match('/\w[-\w.\',;& ]+(ASIA|DLC|EUR|GOTY|JPN|KOR|MULTI\d{1}|NTSCU?|PAL|RF|Region[._ -]?Free|USA|XBLA)[._ -](DLC[._ -]Complete|FRENCH|GERMAN|MULTI\d{1}|PROPER|PSN|READ[._ -]?NFO|UMD)?[._ -]?(GC|NDS|NGC|PS3|PSP|WII|XBOX(360)?)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'gameCheck: Videogames 1', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+(GC|NDS|NGC|PS3|WII|XBOX(360)?)[._ -](DUPLEX|iNSOMNi|OneUp|STRANGE|SWAG|SKY)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'gameCheck: Videogames 2', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[\w.\',;-].+-OUTLAWS/i', $release['textstring'], $result)) {
                $result = str_replace('OUTLAWS', 'PC GAME OUTLAWS', $result['0']);
                $this->updateRelease($release, $result['0'], $method = 'gameCheck: PC Games -OUTLAWS', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[\w.\',;-].+\-ALiAS/i', $release['textstring'], $result)) {
                $newresult = str_replace('-ALiAS', ' PC GAME ALiAS', $result['0']);
                $this->updateRelease($release, $newresult, $method = 'gameCheck: PC Games -ALiAS', $echo, $type, $namestatus, $show);
            }
        }
    }

    /**
     * Look for a app name.
     *
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     * @throws \Exception
     */
    public function appCheck($release, $echo, $type, $namestatus, $show): void
    {
        $result = [];

        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            if (preg_match('/\w[-\w.\',;& ]+(\d{1,10}|Linux|UNIX)[._ -](RPM)?[._ -]?(X64)?[._ -]?(Incl)[._ -](Keygen)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'appCheck: Apps 1', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/\w[-\w.\',;& ]+\d{1,8}[._ -](winall-freeware)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['0'], $method = 'appCheck: Apps 2', $echo, $type, $namestatus, $show);
            }
        }
    }

    /**
     * TV.
     *
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     * @throws \Exception
     */
    public function nfoCheckTV($release, $echo, $type, $namestatus, $show): void
    {
        $result = [];

        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            if (preg_match('/:\s*.*[\\\\\/]([A-Z0-9].+?S\d+[.-_ ]?[ED]\d+.+?)\.\w{2,}\s+/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['1'], $method = 'nfoCheck: Generic TV 1', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/(?:(\:\s{1,}))(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+?)(\s{2,}|\r|\n)/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['2'], $method = 'nfoCheck: Generic TV 2', $echo, $type, $namestatus, $show);
            }
        }
    }

    /**
     * Movies.
     *
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     * @throws \Exception
     */
    public function nfoCheckMov($release, $echo, $type, $namestatus, $show): void
    {
        $result = [];

        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            if (preg_match('/(?:((?!Source\s)\:\s{1,}))(.+?(19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['2'], $method = 'nfoCheck: Generic Movies 1', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/(?:(\s{2,}))((?!Source).+?[\.\-_ ](19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['2'], $method = 'nfoCheck: Generic Movies 2', $echo, $type, $namestatus, $show);
            } elseif (preg_match('/(?:(\s{2,}))(.+?[\.\-_ ](NTSC|MULTi).+?(MULTi|DVDR)[\.\-_ ].+?)(\s{2,}|\r|\n)/i', $release['textstring'], $result)) {
                $this->updateRelease($release, $result['2'], $method = 'nfoCheck: Generic Movies 3', $echo, $type, $namestatus, $show);
            }
        }
    }

    /**
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     * @throws \Exception
     */
    public function nfoCheckMus($release, $echo, $type, $namestatus, $show): void
    {
        $result = [];

        if ($this->done === false && $this->relid !== (int) $release['releases_id'] && preg_match('/(?:\s{2,})(.+?-FM-\d{2}-\d{2})/i', $release['textstring'], $result)) {
            $newname = str_replace('-FM-', '-FM-Radio-MP3-', $result['1']);
            $this->updateRelease($release, $newname, $method = 'nfoCheck: Music FM RADIO', $echo, $type, $namestatus, $show);
        }
    }

    /**
     * Title (year).
     *
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     * @throws \Exception
     */
    public function nfoCheckTY($release, $echo, $type, $namestatus, $show): void
    {
        $result = [];

        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            if (preg_match('/(\w[-\w`~!@#$%^&*()_+={}|"<>?\[\]\\;\',.\/ ]+\s?\((19|20)\d\d\))/i', $release['textstring'], $result) && ! preg_match('/\.pdf|Audio ?Book/i', $release['textstring'])) {
                $releaseName = $result[0];
                if (preg_match('/(idiomas|lang|language|langue|sprache).*?\b(?P<lang>Brazilian|Chinese|Croatian|Danish|DE|Deutsch|Dutch|Estonian|ES|English|Englisch|Finnish|Flemish|Francais|French|FR|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)\b/i', $release['textstring'], $result)) {
                    switch ($result['lang']) {
                        case 'DE':
                            $result['lang'] = 'DUTCH';
                            break;
                        case 'Englisch':
                            $result['lang'] = 'ENGLISH';
                            break;
                        case 'FR':
                            $result['lang'] = 'FRENCH';
                            break;
                        case 'ES':
                            $result['lang'] = 'SPANISH';
                            break;
                        default:
                            break;
                    }
                    $releaseName = $releaseName.'.'.$result['lang'];
                }

                if (preg_match('/(frame size|(video )?res(olution)?|video).*?(?P<res>(272|336|480|494|528|608|\(?640|688|704|720x480|810|816|820|1 ?080|1280( \@)?|1 ?920(x1080)?))/i', $release['textstring'], $result)) {
                    switch ($result['res']) {
                        case '272':
                        case '336':
                        case '480':
                        case '494':
                        case '608':
                        case '640':
                        case '(640':
                        case '688':
                        case '704':
                        case '720x480':
                            $result['res'] = '480p';
                            break;
                        case '1280x720':
                        case '1280':
                        case '1280 @':
                            $result['res'] = '720p';
                            break;
                        case '810':
                        case '816':
                        case '820':
                        case '1920':
                        case '1 920':
                        case '1080':
                        case '1 080':
                        case '1920x1080':
                            $result['res'] = '1080p';
                            break;
                        case '2160':
                            $result['res'] = '2160p';
                            break;
                    }

                    $releaseName = $releaseName.'.'.$result['res'];
                } elseif (preg_match('/(largeur|width).*?(?P<res>(\(?640|688|704|720|1280( \@)?|1 ?920))/i', $release['textstring'], $result)) {
                    switch ($result['res']) {
                        case '640':
                        case '(640':
                        case '688':
                        case '704':
                        case '720':
                            $result['res'] = '480p';
                            break;
                        case '1280 @':
                        case '1280':
                            $result['res'] = '720p';
                            break;
                        case '1920':
                        case '1 920':
                            $result['res'] = '1080p';
                            break;
                        case '2160':
                            $result['res'] = '2160p';
                            break;
                    }

                    $releaseName = $releaseName.'.'.$result['res'];
                }

                if (preg_match('/source.*?\b(?P<source>BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)\b/i', $release['textstring'], $result)) {
                    switch ($result['source']) {
                        case 'BD':
                            $result['source'] = 'Bluray.x264';
                            break;
                        case 'CAMRIP':
                            $result['source'] = 'CAM';
                            break;
                        case 'DBrip':
                            $result['source'] = 'BDRIP';
                            break;
                        case 'DVD R1':
                        case 'NTSC':
                        case 'PAL':
                        case 'VOD':
                            $result['source'] = 'DVD';
                            break;
                        case 'HD':
                            $result['source'] = 'HDTV';
                            break;
                        case 'Ripped ':
                            $result['source'] = 'DVDRIP';
                    }

                    $releaseName = $releaseName.'.'.$result['source'];
                } elseif (preg_match('/(codec( (name|code))?|(original )?format|res(olution)|video( (codec|format|res))?|tv system|type|writing library).*?\b(?P<video>AVC|AVI|DBrip|DIVX|\(Divx|DVD|[HX][._ -]?264|MPEG-4 Visual|NTSC|PAL|WMV|XVID)\b/i', $release['textstring'], $result)) {
                    switch ($result['video']) {
                        case 'AVI':
                            $result['video'] = 'DVDRIP';
                            break;
                        case 'DBrip':
                            $result['video'] = 'BDRIP';
                            break;
                        case '(Divx':
                            $result['video'] = 'DIVX';
                            break;
                        case 'h264':
                        case 'h-264':
                        case 'h.264':
                            $result['video'] = 'H264';
                            break;
                        case 'MPEG-4 Visual':
                        case 'x264':
                        case 'x-264':
                        case 'x.264':
                            $result['video'] = 'x264';
                            break;
                        case 'NTSC':
                        case 'PAL':
                            $result['video'] = 'DVD';
                            break;
                    }

                    $releaseName = $releaseName.'.'.$result['video'];
                }

                if (preg_match('/(audio( format)?|codec( name)?|format).*?\b(?P<audio>0x0055 MPEG-1 Layer 3|AAC( LC)?|AC-?3|\(AC3|DD5(.1)?|(A_)?DTS-?(HD)?|Dolby(\s?TrueHD)?|TrueHD|FLAC|MP3)\b/i', $release['textstring'], $result)) {
                    switch ($result['audio']) {
                        case '0x0055 MPEG-1 Layer 3':
                            $result['audio'] = 'MP3';
                            break;
                        case 'AC-3':
                        case '(AC3':
                            $result['audio'] = 'AC3';
                            break;
                        case 'AAC LC':
                            $result['audio'] = 'AAC';
                            break;
                        case 'A_DTS':
                        case 'DTS-HD':
                        case 'DTSHD':
                            $result['audio'] = 'DTS';
                    }
                    $releaseName = $releaseName.'.'.$result['audio'];
                }
                $releaseName .= '-NoGroup';
                $this->updateRelease($release, $releaseName, $method = 'nfoCheck: Title (Year)', $echo, $type, $namestatus, $show);
            }
        }
    }

    /**
     * Games.
     *
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     * @throws \Exception
     */
    public function nfoCheckG($release, $echo, $type, $namestatus, $show): void
    {
        $result = [];

        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            if (preg_match('/ALiAS|BAT-TEAM|FAiRLiGHT|Game Type|Glamoury|HI2U|iTWINS|JAGUAR|(LARGE|MEDIUM)ISO|MAZE|nERv|PROPHET|PROFiT|PROCYON|RELOADED|REVOLVER|ROGUE|ViTALiTY/i', $release['textstring'])) {
                if (preg_match('/\w[\w.+&*\/\()\',;: -]+\(c\)[-\w.\',;& ]+\w/i', $release['textstring'], $result)) {
                    $releaseName = str_replace(['(c)', '(C)'], '(GAMES) (c)', $result['0']);
                    $this->updateRelease($release, $releaseName, $method = 'nfoCheck: PC Games (c)', $echo, $type, $namestatus, $show);
                } elseif (preg_match('/\w[\w.+&*\/()\',;: -]+\*ISO\*/i', $release['textstring'], $result)) {
                    $releaseName = str_replace('*ISO*', '*ISO* (PC GAMES)', $result['0']);
                    $this->updateRelease($release, $releaseName, $method = 'nfoCheck: PC Games *ISO*', $echo, $type, $namestatus, $show);
                }
            }
        }
    }

    //

    /**
     * Misc.
     *
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     * @throws \Exception
     */
    public function nfoCheckMisc($release, $echo, $type, $namestatus, $show): void
    {
        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            if (preg_match('/Supplier.+?IGUANA/i', $release['textstring'])) {
                $releaseName = '';
                $result = [];
                if (preg_match('/\w[-\w`~!@#$%^&*()+={}|:"<>?\[\]\\;\',.\/ ]+\s\((19|20)\d\d\)/i', $release['textstring'], $result)) {
                    $releaseName = $result[0];
                } elseif (preg_match('/\s\[\*\] (English|Dutch|French|German|Spanish)\b/i', $release['textstring'], $result)) {
                    $releaseName = $releaseName.'.'.$result[1];
                } elseif (preg_match('/\s\[\*\] (DT?S [2567][._ -][0-2]( MONO)?)\b/i', $release['textstring'], $result)) {
                    $releaseName = $releaseName.'.'.$result[2];
                } elseif (preg_match('/Format.+(DVD(5|9|R)?|[HX][._ -]?264)\b/i', $release['textstring'], $result)) {
                    $releaseName = $releaseName.'.'.$result[1];
                } elseif (preg_match('/\[(640x.+|1280x.+|1920x.+)\] Resolution\b/i', $release['textstring'], $result)) {
                    if ($result[1] === '640x.+') {
                        $result[1] = '480p';
                    } elseif ($result[1] === '1280x.+') {
                        $result[1] = '720p';
                    } elseif ($result[1] === '1920x.+') {
                        $result[1] = '1080p';
                    }
                    $releaseName = $releaseName.'.'.$result[1];
                }
                $result = $releaseName.'.IGUANA';
                $this->updateRelease($release, $result, $method = 'nfoCheck: IGUANA', $echo, $type, $namestatus, $show);
            }
        }
    }

    /**
     * Just for filenames.
     *
     * @param         $release
     * @param bool $echo
     * @param string $type
     * @param         $namestatus
     * @param         $show
     *
     * @return bool
     * @throws \Exception
     */
    public function fileCheck($release, $echo, $type, $namestatus, $show): bool
    {
        $result = [];

        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            switch (true) {
                case preg_match('/^(.+?(x264|XviD)\-TVP)\\\\/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['1'], $method = 'fileCheck: TVP', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+)\.(.+)$/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['4'], $method = 'fileCheck: Generic TV', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?([\.\-_ ]\d{4}[\.\-_ ].+?(BDRip|bluray|DVDRip|XVID)).+)\.(.+)$/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['4'], $method = 'fileCheck: Generic movie 1', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/^([a-z0-9\.\-_]+(19|20)\d\d[a-z0-9\.\-_]+[\.\-_ ](720p|1080p|BDRip|bluray|DVDRip|x264|XviD)[a-z0-9\.\-_]+)\.[a-z]{2,}$/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['1'], $method = 'fileCheck: Generic movie 2', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/(.+?([\.\-_ ](CD|FM)|[\.\-_ ]\dCD|CDR|FLAC|SAT|WEB).+?(19|20)\d\d.+?)\\\\.+/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['1'], $method = 'fileCheck: Generic music', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/^(.+?(19|20)\d\d\-([a-z0-9]{3}|[a-z]{2,}|C4))\\\\/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['1'], $method = 'fileCheck: music groups', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/.+\\\\(.+\((19|20)\d\d\)\.avi)/i', $release['textstring'], $result):
                    $newname = str_replace('.avi', ' DVDRip XVID NoGroup', $result['1']);
                    $this->updateRelease($release, $newname, $method = 'fileCheck: Movie (year) avi', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/.+\\\\(.+\((19|20)\d\d\)\.iso)/i', $release['textstring'], $result):
                    $newname = str_replace('.iso', ' DVD NoGroup', $result['1']);
                    $this->updateRelease($release, $newname, $method = 'fileCheck: Movie (year) iso', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/^(.+?IMAGESET.+?)\\\\.+/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['1'], $method = 'fileCheck: XXX Imagesets', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/^VIDEOOT-[A-Z0-9]+\\\\([\w!.,& ()\[\]\'\`-]{8,}?\b.?)([-_](proof|sample|thumbs?))*(\.part\d*(\.rar)?|\.rar|\.7z)?(\d{1,3}\.rev|\.vol.+?|\.mp4)/', $release['textstring'], $result):
                    $this->updateRelease($release, $result['1'].' XXX DVDRIP XviD-VIDEOOT', $method = 'fileCheck: XXX XviD VIDEOOT', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/^.+?SDPORN/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['0'], $method = 'fileCheck: XXX SDPORN', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/\w[-\w.\',;& ]+1080i[._ -]DD5[._ -]1[._ -]MPEG2-R&C(?=\.ts)/i', $release['textstring'], $result):
                    $result = str_replace('MPEG2', 'MPEG2.HDTV', $result['0']);
                    $this->updateRelease($release, $result, $method = 'fileCheck: R&C', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/\w[-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -]nSD[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -]NhaNC3[-\w.\',;& ]+\w/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['0'], $method = 'fileCheck: NhaNc3', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/\wtvp-[\w.\-\',;]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](720p|1080p|xvid)(?=\.(avi|mkv))/i', $release['textstring'], $result):
                    $result = str_replace('720p', '720p.HDTV.X264', $result['0']);
                    $result = str_replace('1080p', '1080p.Bluray.X264', $result['0']);
                    $result = str_replace('xvid', 'XVID.DVDrip', $result['0']);
                    $this->updateRelease($release, $result, $method = 'fileCheck: tvp', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/\w[-\w.\',;& ]+\d{3,4}\.hdtv-lol\.(avi|mp4|mkv|ts|nfo|nzb)/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['0'], $method = 'fileCheck: Title.211.hdtv-lol.extension', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/\w[-\w.\',;& ]+-S\d{1,2}[EX]\d{1,2}-XVID-DL.avi/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['0'], $method = 'fileCheck: Title-SxxExx-XVID-DL.avi', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/\S.*[\w.\-\',;]+\s\-\ss\d{2}[ex]\d{2}\s\-\s[\w.\-\',;].+\./i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['0'], $method = 'fileCheck: Title - SxxExx - Eptitle', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/\w.+?\)\.nds/i', $release['textstring'], $result):
                    $this->updateRelease($release, $result['0'], $method = 'fileCheck: ).nds Nintendo DS', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/3DS_\d{4}.+\d{4} - (.+?)\.3ds/i', $release['textstring'], $result):
                    $this->updateRelease($release, '3DS '.$result['1'], $method = 'fileCheck: .3ds Nintendo 3DS', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/\w.+?\.(epub|mobi|azw|opf|fb2|prc|djvu|cb[rz])/i', $release['textstring'], $result):
                    $result = str_replace('.'.$result['1'], ' ('.$result['1'].')', $result['0']);
                    $this->updateRelease($release, $result, $method = 'fileCheck: EBook', $echo, $type, $namestatus, $show);
                    break;
                case preg_match('/\w+[-\w.\',;& ]+$/i', $release['textstring'], $result) && preg_match(self::PREDB_REGEX, $release['textstring']):
                    $this->updateRelease($release, $result['0'], $method = 'fileCheck: Folder name', $echo, $type, $namestatus, $show);
                    break;
                default:
                    return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Look for a name based on mediainfo xml Unique_ID.
     *
     * @param array $release The release to be matched
     * @param bool $echo Should we show CLI output
     * @param string $type The rename type
     * @param int $namestatus Should we rename the release if match is found
     * @param int $show Should we show the rename results
     *
     * @return bool Whether or not we matched the release
     * @throws \Exception
     */
    public function uidCheck($release, $echo, $type, $namestatus, $show): bool
    {
        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            $result = $this->pdo->queryDirect(
                "
				SELECT r.id AS releases_id, r.size AS relsize, r.name AS textstring, r.searchname, r.fromname, r.predb_id
				FROM releases r
				LEFT JOIN release_unique ru ON ru.releases_id = r.id
				WHERE ru.releases_id IS NOT NULL
				AND ru.uniqueid = UNHEX({$this->pdo->escapeString($release['uid'])})
				AND ru.releases_id != {$release['releases_id']}
				AND (r.predb_id > 0 OR r.anidbid > 0 OR r.fromname = 'nonscene@Ef.net (EF)')"
            );

            if ($result instanceof \Traversable) {
                foreach ($result as $res) {
                    $floor = round(($res['relsize'] - $release['relsize']) / $res['relsize'] * 100, 1);
                    if ($floor >= -10 && $floor <= 10) {
                        $this->updateRelease(
                            $release,
                            $res['searchname'],
                            $method = 'uidCheck: Unique_ID',
                            $echo,
                            $type,
                            $namestatus,
                            $show,
                            $res['predb_id']
                        );

                        return true;
                    }
                }
            }
        }
        $this->_updateSingleColumn('proc_uid', self::PROC_UID_DONE, $release['releases_id']);

        return false;
    }

    /**
     * Look for a name based on mediainfo xml Unique_ID.
     *
     * @param array $release The release to be matched
     * @param bool $echo Should we show CLI output
     * @param string $type The rename type
     * @param int $namestatus Should we rename the release if match is found
     * @param int $show Should we show the rename results
     *
     * @return bool Whether or not we matched the release
     * @throws \Exception
     */
    public function mediaMovieNameCheck($release, $echo, $type, $namestatus, $show): bool
    {
        $newName = '';
        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            if (preg_match('/<Movie_name>(.+)<\/Movie_name>/i', $release['mediainfo'], $match)) {
                $media = $match[1];
                if (preg_match(self::PREDB_REGEX, $media, $match)) {
                    $newName = $match[1];
                } elseif (preg_match('/(.+)[\,](\sRMZ\.cr)?/i', $media, $match)) {
                    $newName = $match[1];
                } else {
                    $newName = $media;
                }
            }

            if ($newName !== '') {
                $this->updateRelease($release, $newName, $method = 'MediaInfo: Movie Name', $echo, $type, $namestatus, $show, $release['predb_id']);

                return true;
            }
        }
        $this->_updateSingleColumn('proc_uid', self::PROC_UID_DONE, $release['releases_id']);

        return false;
    }

    /**
     * Look for a name based on xxx release filename.
     *
     * @param array $release The release to be matched
     * @param bool $echo Should we show CLI output
     * @param string $type The rename type
     * @param int $namestatus Should we rename the release if match is found
     * @param int $show Should we show the rename results
     *
     * @return bool Whether or not we matched the release
     * @throws \Exception
     * @throws \RuntimeException
     */
    public function xxxNameCheck($release, $echo, $type, $namestatus, $show): bool
    {
        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            $result = $this->pdo->queryDirect(
                sprintf(
                    "
				SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = {$release['releases_id']})
					WHERE (rel.isrenamed = %d OR rel.categories_id IN(%d, %d))
					AND rf.name %s",
                    self::IS_RENAMED_NONE,
                    Category::OTHER_MISC,
                    Category::OTHER_HASHED,
                    $this->pdo->likeString('SDPORN', true, true)
                )
            );

            if ($result instanceof \Traversable) {
                foreach ($result as $res) {
                    if (preg_match('/^.+?SDPORN/i', $res['textstring'], $match)) {
                        $this->updateRelease(
                            $release,
                            $match['0'],
                            $method = 'fileCheck: XXX SDPORN',
                            $echo,
                            $type,
                            $namestatus,
                            $show
                        );

                        return true;
                    }
                }
            }
        }
        $this->_updateSingleColumn('proc_files', self::PROC_FILES_DONE, $release['releases_id']);

        return false;
    }

    /**
     * Look for a name based on .srr release files extension.
     *
     * @param array $release The release to be matched
     * @param bool $echo Should we show CLI output
     * @param string $type The rename type
     * @param int $namestatus Should we rename the release if match is found
     * @param int $show Should we show the rename results
     *
     * @return bool Whether or not we matched the release
     * @throws \Exception
     * @throws \RuntimeException
     */
    public function srrNameCheck($release, $echo, $type, $namestatus, $show): bool
    {
        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            $result = $this->pdo->queryDirect(
                sprintf(
                    "
				SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
						rf.releases_id AS fileid, rel.id AS releases_id
					FROM releases rel
					INNER JOIN release_files rf ON (rf.releases_id = {$release['releases_id']})
					WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
					AND rf.name %s",
                    self::IS_RENAMED_NONE,
                    Category::OTHER_MISC,
                    Category::OTHER_HASHED,
                    $this->pdo->likeString('.srr', true, false)
                )
            );

            if ($result instanceof \Traversable) {
                foreach ($result as $res) {
                    if (preg_match('/^(.*)\.srr/i', $res['textstring'], $match)) {
                        $this->updateRelease(
                            $release,
                            $match['1'],
                            $method = 'fileCheck: SRR extension',
                            $echo,
                            $type,
                            $namestatus,
                            $show
                        );

                        return true;
                    }
                }
            }
        }
        $this->_updateSingleColumn('proc_srr', self::PROC_SRR_DONE, $release['releases_id']);

        return false;
    }

    /**
     * Look for a name based on par2 hash_16K block.
     *
     * @param array $release The release to be matched
     * @param bool $echo Should we show CLI output
     * @param string $type The rename type
     * @param int $namestatus Should we rename the release if match is found
     * @param int $show Should we show the rename results
     *
     * @return bool Whether or not we matched the release
     * @throws \Exception
     * @throws \RuntimeException
     */
    public function hashCheck($release, $echo, $type, $namestatus, $show): bool
    {
        if ($this->done === false && $this->relid !== (int) $release['releases_id']) {
            $result = $this->pdo->queryDirect(
                "
				SELECT r.id AS releases_id, r.size AS relsize, r.name AS textstring, r.searchname, r.fromname, r.predb_id
				FROM releases r
				STRAIGHT_JOIN par_hashes ph ON ph.releases_id = r.id
				WHERE ph.hash = {$this->pdo->escapeString($release['hash'])}
				AND ph.releases_id != {$release['releases_id']}
				AND (r.predb_id > 0 OR r.anidbid > 0)"
            );

            if ($result instanceof \Traversable) {
                foreach ($result as $res) {
                    $floor = round(($res['relsize'] - $release['relsize']) / $res['relsize'] * 100, 1);
                    if ($floor >= -5 && $floor <= 5) {
                        $this->updateRelease(
                            $release,
                            $res['searchname'],
                            $method = 'hashCheck: PAR2 hash_16K',
                            $echo,
                            $type,
                            $namestatus,
                            $show,
                            $res['predb_id']
                        );

                        return true;
                    }
                }
            }
        }
        $this->_updateSingleColumn('proc_hash16k', self::PROC_HASH16K_DONE, $release['releases_id']);

        return false;
    }

    /**
     * Resets NameFixer status variables for new processing.
     */
    public function reset(): void
    {
        $this->done = $this->matched = false;
    }
}
