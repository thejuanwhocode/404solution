<?php

/* Finds similar pages. 
 * Finds search suggestions. */

class ABJ_404_Solution_SpellChecker {
    
    /** Use spell checking to find the correct link. Return the permalink (map) if there is one, otherwise return null.
     * @global type $abj404spellChecker
     * @global type $abj404logic
     * @param type $requestedURL
     * @return type
     */
    function getSpellCheckedRedirectID($requestedURL) {
        global $abj404spellChecker;
        global $abj404logic;

        $options = $abj404logic->getOptions();

        $found = 0;
        if (@$options['auto_redirects'] == '1') {
            // Site owner wants automatic redirects.
            $permalinks = $abj404spellChecker->findMatchingPosts($requestedURL, $options['auto_cats'], $options['auto_tags']);
            $minScore = $options['auto_score'];

            // since the links were previously sorted so that the highest score would be first, 
            // we only use the first element of the array;
            $linkScore = reset($permalinks);
            $idAndType = key($permalinks);
            $permalink = ABJ_404_Solution_Functions::permalinkInfoToArray($idAndType, $linkScore);

            if ($permalink['score'] >= $minScore) {
                $found = 1;
            }

            if ($found == 1) {
                // We found a permalink that will work!
                $type = 0;
                if ($permalink['type'] == "POST") {
                    $type = ABJ404_POST;
                } else if ($permalink['type'] == "CAT") {
                    $type = ABJ404_CAT;
                } else if ($permalink['type'] == "TAG") {
                    $type = ABJ404_TAG;
                }
                if ($type != 0) {
                    return $permalink;

                } else {
                    ABJ_404_Solution_Functions::errorMessage("Unhandled permalink type: " . 
                            wp_kses(json_encode($permalink), array()));
                    return null;
                }
            }
        }
        
        return null;
    }

    /** Returns a list of 
     * @global type $wpdb
     * @param type $url
     * @param type $includeCats
     * @param type $includeTags
     * @return type
     */
    function findMatchingPosts($url, $includeCats = '1', $includeTags = '1') {
        global $abj404dao;
        $permalinks = array();

        $rows = $abj404dao->getPublishedPagesAndPostsIDs();
        foreach ($rows as $row) {
            $id = $row->id;
            $the_permalink = get_permalink($id);
            $urlParts = parse_url($the_permalink);
            $scoreBasis = strlen($urlParts['path']);
            $levscore = levenshtein($url, $urlParts['path'], 1, 1, 1);
            $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
            $permalinks[$id . "|POST"] = number_format($score, 4, '.', '');

            // if the slug is in the URL then the user wants the post with the same slug.
            // to avoid an issue where a slug is a subset of another slug, we prefer the matching slug
            // with the longest length. e.g.
            /* url from user: www.site.com/a-post-slug
             * post 1 slug: a-post-slug  // matches (contained in url).
             * post 2 slug: a-post-slug-longer // does not match the url (not contained in url).
             *
              /* url from user: www.site.com/a-post-slug-longer
             * post 1 slug: a-post-slug  // matches with a score + length of the slug.
             * post 2 slug: a-post-slug-longer // matches with a score + length of the slug.
             *
             * therefore the longer slug has a higher score and takes priority.
             * this is important for when permalinks change.
             */
            $post = get_post($id);
            $postSlug = strtolower($post->post_name);
            if (strpos(strtolower($url), $postSlug) !== false) {
                $permalinks[$id . "|POST"] = number_format(100 + strlen($postSlug), 4, '.', '');
            }
        }

        if ($includeTags == "1") {
            $rows = $abj404dao->getPublishedTags();
            foreach ($rows as $row) {
                $id = $row->term_id;
                $the_permalink = get_tag_link($id);
                $urlParts = parse_url($the_permalink);
                $scoreBasis = strlen($urlParts['path']);
                $levscore = levenshtein($url, $urlParts['path'], 1, 1, 1);
                $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
                $permalinks[$id . "|TAG"] = number_format($score, 4, '.', '');
            }
        }

        if ($includeCats == "1") {
            $rows = $abj404dao->getPublishedCategories();
            foreach ($rows as $row) {
                $id = $row->term_id;
                $the_permalink = get_category_link($id);
                $urlParts = parse_url($the_permalink);
                $scoreBasis = strlen($urlParts['path']);
                $levscore = levenshtein($url, $urlParts['path'], 1, 1, 1);
                $score = 100 - ( ( $levscore / $scoreBasis ) * 100 );
                $permalinks[$id . "|CAT"] = number_format($score, 4, '.', '');
            }
        }

        // This is sorted so that the link with the highest score will be first when iterating through.
        arsort($permalinks);
        
        return $permalinks;
    }

}
