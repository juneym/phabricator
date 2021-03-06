<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class PhabricatorRepositoryGitCommitChangeParserWorker
  extends PhabricatorRepositoryCommitChangeParserWorker {

  protected function parseCommit(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {

    $full_name = 'r'.$repository->getCallsign().$commit->getCommitIdentifier();
    echo "Parsing {$full_name}...\n";
    if ($this->isBadCommit($full_name)) {
      echo "This commit is marked bad!\n";
      return;
    }

    // NOTE: "--pretty=format: " is to disable log output, we only want the
    // part we get from "--raw".
    list($raw) = $repository->execxLocalCommand(
      'log -n1 -M -C -B --find-copies-harder --raw -t '.
        '--abbrev=40 --pretty=format: %s',
      $commit->getCommitIdentifier());

    $changes = array();
    $move_away = array();
    $copy_away = array();
    $lines = explode("\n", $raw);
    foreach ($lines as $line) {
      if (!strlen(trim($line))) {
        continue;
      }
      list($old_mode, $new_mode,
           $old_hash, $new_hash,
           $more_stuff) = preg_split('/ +/', $line);

      // We may only have two pieces here.
      list($action, $src_path, $dst_path) = array_merge(
        explode("\t", $more_stuff),
        array(null));

      // Normalize the paths for consistency with the SVN workflow.
      $src_path = '/'.$src_path;
      if ($dst_path) {
        $dst_path = '/'.$dst_path;
      }

      $old_mode = intval($old_mode, 8);
      $new_mode = intval($new_mode, 8);

      $file_type = DifferentialChangeType::FILE_NORMAL;
      if ($new_mode & 040000) {
        $file_type = DifferentialChangeType::FILE_DIRECTORY;
      } else if ($new_mode & 0120000) {
        $file_type = DifferentialChangeType::FILE_SYMLINK;
      }

      // TODO: We can detect binary changes as git does, through a combination
      // of running 'git check-attr' for stuff like 'binary', 'merge' or 'diff',
      // and by falling back to inspecting the first 8,000 characters of the
      // buffer for null bytes (this is seriously git's algorithm, see
      // buffer_is_binary() in xdiff-interface.c).

      $change_type = null;
      $change_path = $src_path;
      $change_target = null;
      $is_direct = true;

      switch ($action[0]) {
        case 'A':
          $change_type = DifferentialChangeType::TYPE_ADD;
          break;
        case 'D':
          $change_type = DifferentialChangeType::TYPE_DELETE;
          break;
        case 'C':
          $change_type = DifferentialChangeType::TYPE_COPY_HERE;
          $change_path = $dst_path;
          $change_target = $src_path;
          $copy_away[$change_target][] = $change_path;
          break;
        case 'R':
          $change_type = DifferentialChangeType::TYPE_MOVE_HERE;
          $change_path = $dst_path;
          $change_target = $src_path;
          $move_away[$change_target][] = $change_path;
          break;
        case 'T':
          // Type of the file changed, fall through and treat it as a
          // modification. Not 100% sure this is the right thing to do but it
          // seems reasonable.
        case 'M':
          if ($file_type == DifferentialChangeType::FILE_DIRECTORY) {
            $change_type = DifferentialChangeType::TYPE_CHILD;
            $is_direct = false;
          } else {
            $change_type = DifferentialChangeType::TYPE_CHANGE;
          }
          break;
        // NOTE: "U" (unmerged) and "X" (unknown) statuses are also possible
        // in theory but shouldn't appear here.
        default:
          throw new Exception("Failed to parse line '{$line}'.");
      }

      $changes[$change_path] = array(
        'repositoryID'      => $repository->getID(),
        'commitID'          => $commit->getID(),

        'path'              => $change_path,
        'changeType'        => $change_type,
        'fileType'          => $file_type,
        'isDirect'          => $is_direct,
        'commitSequence'    => $commit->getEpoch(),

        'targetPath'        => $change_target,
        'targetCommitID'    => $change_target ? $commit->getID() : null,
      );
    }

    // Add a change to '/' since git doesn't mention it.
    $changes['/'] = array(
      'repositoryID'      => $repository->getID(),
      'commitID'          => $commit->getID(),

      'path'              => '/',
      'changeType'        => DifferentialChangeType::TYPE_CHILD,
      'fileType'          => DifferentialChangeType::FILE_DIRECTORY,
      'isDirect'          => false,
      'commitSequence'    => $commit->getEpoch(),

      'targetPath'        => null,
      'targetCommitID'    => null,
    );

    foreach ($copy_away as $change_path => $destinations) {
      if (isset($move_away[$change_path])) {
        $change_type = DifferentialChangeType::TYPE_MULTICOPY;
        $is_direct = true;
        unset($move_away[$change_path]);
      } else {
        $change_type = DifferentialChangeType::TYPE_COPY_AWAY;
        $is_direct = false;
      }

      $reference = $changes[reset($destinations)];

      $changes[$change_path] = array(
        'repositoryID'      => $repository->getID(),
        'commitID'          => $commit->getID(),

        'path'              => $change_path,
        'changeType'        => $change_type,
        'fileType'          => $reference['fileType'],
        'isDirect'          => $is_direct,
        'commitSequence'    => $commit->getEpoch(),

        'targetPath'        => null,
        'targetCommitID'    => null,
      );
    }

    foreach ($move_away as $change_path => $destinations) {
      $reference = $changes[reset($destinations)];

      $changes[$change_path] = array(
        'repositoryID'      => $repository->getID(),
        'commitID'          => $commit->getID(),

        'path'              => $change_path,
        'changeType'        => DifferentialChangeType::TYPE_MOVE_AWAY,
        'fileType'          => $reference['fileType'],
        'isDirect'          => true,
        'commitSequence'    => $commit->getEpoch(),

        'targetPath'        => null,
        'targetCommitID'    => null,
      );
    }

    $paths = array();
    foreach ($changes as $change) {
      $paths[$change['path']] = true;
      if ($change['targetPath']) {
        $paths[$change['targetPath']] = true;
      }
    }

    $path_map = $this->lookupOrCreatePaths(array_keys($paths));

    foreach ($changes as $key => $change) {
      $changes[$key]['pathID'] = $path_map[$change['path']];
      if ($change['targetPath']) {
        $changes[$key]['targetPathID'] = $path_map[$change['targetPath']];
      } else {
        $changes[$key]['targetPathID'] = null;
      }
    }

    $conn_w = $repository->establishConnection('w');

    $changes_sql = array();
    foreach ($changes as $change) {
      $values = array(
        (int)$change['repositoryID'],
        (int)$change['pathID'],
        (int)$change['commitID'],
        $change['targetPathID']
          ? (int)$change['targetPathID']
          : 'null',
        $change['targetCommitID']
          ? (int)$change['targetCommitID']
          : 'null',
        (int)$change['changeType'],
        (int)$change['fileType'],
        (int)$change['isDirect'],
        (int)$change['commitSequence'],
      );
      $changes_sql[] = '('.implode(', ', $values).')';
    }

    queryfx(
      $conn_w,
      'DELETE FROM %T WHERE commitID = %d',
      PhabricatorRepository::TABLE_PATHCHANGE,
      $commit->getID());
    foreach (array_chunk($changes_sql, 256) as $sql_chunk) {
      queryfx(
        $conn_w,
        'INSERT INTO %T
          (repositoryID, pathID, commitID, targetPathID, targetCommitID,
            changeType, fileType, isDirect, commitSequence)
          VALUES %Q',
        PhabricatorRepository::TABLE_PATHCHANGE,
        implode(', ', $sql_chunk));
    }

    $this->finishParse();
  }

}
