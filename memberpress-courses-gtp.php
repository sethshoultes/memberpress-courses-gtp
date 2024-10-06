<?php
/*
Plugin Name: MemberPress ChatGPT Course Generator
Plugin URI: https://example.com/
Description: Generates courses and lessons using ChatGPT and saves them to the MemberPress Courses database.
Version: 1.0
Author: Your Name
Author URI: https://example.com/
*/

// Register the admin menu page
function mpcs_chatgpt_admin_menu() {
    add_menu_page(
        'ChatGPT Course Generator',
        'ChatGPT Course Generator',
        'manage_options',
        'mpcs-chatgpt',
        'mpcs_chatgpt_admin_page'
    );
}
add_action('admin_menu', 'mpcs_chatgpt_admin_menu');

// Admin page callback function
function mpcs_chatgpt_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['submit'])) {
        $course_title = sanitize_text_field($_POST['course_title']);
        $sections = isset($_POST['sections']) ? $_POST['sections'] : array();

        // Generate course using ChatGPT API
        $course_content = generate_course_content($course_title);
        if (is_wp_error($course_content)) {
            echo '<div class="notice notice-error"><p>' . esc_html($course_content->get_error_message()) . '</p></div>';
            return;
        }

        // Save course to the database
        $course_id = wp_insert_post(array(
            'post_title' => $course_title,
            'post_content' => $course_content,
            'post_status' => 'publish',
            'post_type' => 'mpcs-course',
        ));

        if (!$course_id) {
            echo '<div class="notice notice-error"><p>Failed to save the course.</p></div>';
            return;
        }

        foreach ($sections as $section_index => $section_data) {
            $section_title = sanitize_text_field($section_data['title']);
            $num_lessons = intval($section_data['num_lessons']);

            // Generate lessons for this section using ChatGPT API
            $lessons = generate_lesson_content($section_title, $num_lessons);
            if (is_wp_error($lessons)) {
                echo '<div class="notice notice-error"><p>' . esc_html($lessons->get_error_message()) . '</p></div>';
                continue;
            }

            $section_id = wp_insert_post(array(
                'post_title' => $section_title,
                'post_status' => 'publish',
                'post_type' => 'mpcs-section',
                'post_parent' => $course_id,
            ));

            if (!$section_id) {
                echo '<div class="notice notice-error"><p>Failed to save section: ' . esc_html($section_title) . '</p></div>';
                continue;
            }

            foreach ($lessons as $lesson_index => $lesson) {
                $lesson_id = wp_insert_post(array(
                    'post_title' => $lesson['title'],
                    'post_content' => $lesson['content'],
                    'post_status' => 'publish',
                    'post_type' => 'mpcs-lesson',
                    'post_parent' => $section_id,
                ));

                update_post_meta($lesson_id, '_mpcs_lesson_order', $lesson_index + 1);
            }

            echo '<div class="notice notice-success"><p>Section "' . esc_html($section_title) . '" and lessons generated successfully!</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1>ChatGPT Course Generator</h1>
        <form method="post">
            <table class="form-table" id="section-table">
                <tr>
                    <th scope="row"><label for="course_title">Course Title</label></th>
                    <td><input name="course_title" type="text" id="course_title" class="regular-text" required></td>
                </tr>
            </table>
            <button type="button" id="add-section-btn" class="button">Add Section</button>
            <?php submit_button('Generate Course'); ?>
        </form>

        <script type="text/javascript">
            document.getElementById('add-section-btn').addEventListener('click', function () {
                const sectionTable = document.getElementById('section-table');
                const sectionIndex = sectionTable.querySelectorAll('tr').length - 1;

                const sectionRow = `
                    <tr>
                        <th scope="row"><label for="section_title_${sectionIndex}">Section Title</label></th>
                        <td><input name="sections[${sectionIndex}][title]" type="text" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="num_lessons_${sectionIndex}">Number of Lessons</label></th>
                        <td><input name="sections[${sectionIndex}][num_lessons]" type="number" class="regular-text" required></td>
                    </tr>
                `;
                sectionTable.insertAdjacentHTML('beforeend', sectionRow);
            });
        </script>
    </div>
    <?php
}

// Function to generate course content using ChatGPT API (with 'messages' array)
function generate_course_content($course_title) {
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    
    // Construct the 'messages' array for the new API format
    $messages = array(
        array(
            'role' => 'system',
            'content' => 'You are a knowledgeable course creator.',
        ),
        array(
            'role' => 'user',
            'content' => "Create a detailed outline for a course titled: $course_title",
        )
    );

    // Log the prompt for debugging
    error_log("ChatGPT API Request Prompt for Course: Create a detailed outline for a course titled: $course_title");

    // Perform API request
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'body' => json_encode(array(
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'max_tokens' => 1000,
        )),
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
    ));

    // Handle errors in the API response
    if (is_wp_error($response)) {
        error_log('ChatGPT API connection error: ' . $response->get_error_message());
        return new WP_Error('api_error', 'Failed to connect to the ChatGPT API.');
    }

    // Log the full API response for debugging
    $body = wp_remote_retrieve_body($response);
    error_log("ChatGPT API Response for Course: " . $body);

    $data = json_decode($body, true);

    // Check if the response contains a valid result
    if (empty($data['choices'][0]['message']['content'])) {
        error_log('ChatGPT API response error: Invalid response for prompt.');
        error_log('Full ChatGPT Response: ' . print_r($data, true)); // Log the entire response
        return new WP_Error('api_error', 'The ChatGPT API returned an invalid response.');
    }

    return $data['choices'][0]['message']['content']; // Adjusted for the new response structure
}

// Function to generate lesson content using ChatGPT API (with 'messages' array)
function generate_lesson_content($section_title, $num_lessons) {
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';

    // Construct the 'messages' array for the new API format
    $messages = array(
        array(
            'role' => 'system',
            'content' => 'You are a helpful lesson planner.',
        ),
        array(
            'role' => 'user',
            'content' => "Generate $num_lessons lesson titles and content for the section: $section_title",
        )
    );

    // Log the prompt for debugging
    error_log("ChatGPT API Request Prompt for Lessons: Generate $num_lessons lesson titles for section $section_title");

    // Perform API request
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'body' => json_encode(array(
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'max_tokens' => 2000,
        )),
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
    ));

    // Handle errors in the API response
    if (is_wp_error($response)) {
        error_log('ChatGPT API connection error: ' . $response->get_error_message());
        return new WP_Error('api_error', 'Failed to connect to the ChatGPT API.');
    }

    // Log the full API response for debugging
    $body = wp_remote_retrieve_body($response);
    error_log("ChatGPT API Response for Lessons: " . $body);

    $data = json_decode($body, true);

    // Check if the response contains a valid result
    if (empty($data['choices'][0]['message']['content'])) {
        error_log('ChatGPT API response error: Invalid response for prompt.');
        error_log('Full ChatGPT Response: ' . print_r($data, true)); // Log the entire response
        return new WP_Error('api_error', 'The ChatGPT API returned an invalid response.');
    }

    // Parse lessons from the API response
    $lessons = array();
    $lesson_lines = explode("\n", $data['choices'][0]['message']['content']); // Adjusted for new response structure

    for ($i = 0; $i < $num_lessons; $i++) {
        if (isset($lesson_lines[$i * 2]) && isset($lesson_lines[$i * 2 + 1])) {
            $lessons[] = array(
                'title' => trim($lesson_lines[$i * 2]),
                'content' => trim($lesson_lines[$i * 2 + 1]),
            );
        } else {
            // Log if lesson data is incomplete
            error_log("Incomplete lesson data at index $i for section: $section_title");
            $lessons[] = array(
                'title' => 'Untitled Lesson ' . ($i + 1),
                'content' => 'Content is missing for this lesson.',
            );
        }
    }

    return $lessons;
}
