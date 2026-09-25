import json, os

def json_dumps_php(obj):
    return json.dumps(obj, ensure_ascii=False, indent=12)

tests = []

# 1. TEST-G1-G2 (Lớp 1 lên 2)
tests.append({
    "code": "TEST-G1-G2",
    "title": "Đề Test Đánh Giá Năng Lực Đầu Vào Lớp 1 Lên Lớp 2 (Pre-A1 Starters)",
    "target_level": "Tiểu học Pre-A1 (Starters)",
    "duration_minutes": 35,
    "is_preset": True,
    "questions": [
        {
            "id": 1,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 1: Listen and choose the correct picture.",
            "passage": "Audio Exercise 1 (Track 1) - Part 1",
            "options": [
                {"key": "A", "text": "Picture A", "image_url": "/uploads/tests/TEST-G1-G2/image1.png"},
                {"key": "B", "text": "Picture B", "image_url": "/uploads/tests/TEST-G1-G2/image2.png"},
                {"key": "C", "text": "Picture C", "image_url": "/uploads/tests/TEST-G1-G2/image3.png"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Key: Tick picture A"
        },
        {
            "id": 2,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 2: Listen and choose the correct picture.",
            "options": [
                {"key": "A", "text": "Picture A", "image_url": "/uploads/tests/TEST-G1-G2/image4.jpeg"},
                {"key": "B", "text": "Picture B", "image_url": "/uploads/tests/TEST-G1-G2/image5.png"},
                {"key": "C", "text": "Picture C", "image_url": "/uploads/tests/TEST-G1-G2/image6.png"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "Key: Tick picture B"
        },
        {
            "id": 3,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 3: Listen and choose the correct picture.",
            "options": [
                {"key": "A", "text": "Picture A", "image_url": "/uploads/tests/TEST-G1-G2/image7.jpeg"},
                {"key": "B", "text": "Picture B", "image_url": "/uploads/tests/TEST-G1-G2/image8.png"},
                {"key": "C", "text": "Picture C", "image_url": "/uploads/tests/TEST-G1-G2/image9.png"}
            ],
            "correct_answer": "C",
            "points": 1,
            "explanation": "Key: Tick picture C"
        },
        {
            "id": 4,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 4: Listen and choose the correct picture.",
            "options": [
                {"key": "A", "text": "Picture A", "image_url": "/uploads/tests/TEST-G1-G2/image10.png"},
                {"key": "B", "text": "Picture B", "image_url": "/uploads/tests/TEST-G1-G2/image11.png"},
                {"key": "C", "text": "Picture C", "image_url": "/uploads/tests/TEST-G1-G2/image12.png"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Key: Tick picture A"
        },
        {
            "id": 5,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 5: Listen and choose the correct picture.",
            "options": [
                {"key": "A", "text": "Picture A", "image_url": "/uploads/tests/TEST-G1-G2/image13.png"},
                {"key": "B", "text": "Picture B", "image_url": "/uploads/tests/TEST-G1-G2/image14.jpeg"},
                {"key": "C", "text": "Picture C", "image_url": "/uploads/tests/TEST-G1-G2/image15.jpeg"}
            ],
            "correct_answer": "C",
            "points": 1,
            "explanation": "Key: Tick picture C"
        },
        {
            "id": 6,
            "skill": "listening",
            "type": "fill_blank",
            "title": "Question 6: Who is Tom's friend? (Listen and write name)",
            "correct_answer": "KIM",
            "points": 1,
            "explanation": "Tom's friend is Kim."
        },
        {
            "id": 7,
            "skill": "listening",
            "type": "fill_blank",
            "title": "Question 7: How old is Tom's friend?",
            "correct_answer": "7",
            "points": 1,
            "explanation": "Kim is 7 years old."
        },
        {
            "id": 8,
            "skill": "listening",
            "type": "fill_blank",
            "title": "Question 8: Where does Tom live? (in _____ Street)",
            "correct_answer": "Bean",
            "points": 1,
            "explanation": "In Bean Street."
        },
        {
            "id": 9,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 9: Look at the picture and circle the correct word.",
            "image_url": "/uploads/tests/TEST-G1-G2/image16.png",
            "options": [
                {"key": "A", "text": "jumper"},
                {"key": "B", "text": "shoes"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "The image shows shoes."
        },
        {
            "id": 10,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 10: Look at the picture and circle the correct word.",
            "image_url": "/uploads/tests/TEST-G1-G2/image17.jpeg",
            "options": [
                {"key": "A", "text": "pencil"},
                {"key": "B", "text": "crayon"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "The image shows a pencil."
        },
        {
            "id": 11,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 11: Look at the picture and circle the correct word.",
            "image_url": "/uploads/tests/TEST-G1-G2/image18.jpeg",
            "options": [
                {"key": "A", "text": "teacher"},
                {"key": "B", "text": "doctor"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "The image shows a teacher."
        },
        {
            "id": 12,
            "skill": "reading",
            "type": "fill_blank",
            "title": "Question 12: Unscramble the letters to make a word: A S F O",
            "image_url": "/uploads/tests/TEST-G1-G2/image22.png",
            "correct_answer": "SOFA",
            "points": 1,
            "explanation": "A S F O -> SOFA"
        },
        {
            "id": 13,
            "skill": "reading",
            "type": "fill_blank",
            "title": "Question 13: Unscramble the letters to make a word: M P A L",
            "image_url": "/uploads/tests/TEST-G1-G2/image23.png",
            "correct_answer": "LAMP",
            "points": 1,
            "explanation": "M P A L -> LAMP"
        },
        {
            "id": 14,
            "skill": "reading",
            "type": "true_false",
            "title": "Question 14: Look at the picture. Sentence: 'There are two boys in the room.' (Yes or No)",
            "image_url": "/uploads/tests/TEST-G1-G2/image25.png",
            "options": [
                {"key": "A", "text": "Yes"},
                {"key": "B", "text": "No"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "No"
        },
        {
            "id": 15,
            "skill": "reading",
            "type": "true_false",
            "title": "Question 15: Look at the picture. Sentence: 'The boy with the black hair is taking a photo.' (Yes or No)",
            "image_url": "/uploads/tests/TEST-G1-G2/image25.png",
            "options": [
                {"key": "A", "text": "Yes"},
                {"key": "B", "text": "No"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Yes"
        },
        {
            "id": 16,
            "skill": "speaking",
            "type": "speaking_prompt",
            "title": "Question 16: Oral Interview - Introduce yourself, your family and your favourite toy/color.",
            "cue_points": "1. What is your name?\\n2. How old are you?\\n3. What is your favourite toy or animal?\\n4. Point to the object in the room and name it.",
            "points": 9,
            "explanation": "Speaking Pre-A1 Assessment"
        }
    ]
})

# 2. TEST-G2-G3 (Lớp 2 lên 3)
tests.append({
    "code": "TEST-G2-G3",
    "title": "Đề Test Đánh Giá Năng Lực Đầu Vào Lớp 2 Lên Lớp 3 (Starters - Movers)",
    "target_level": "Tiểu học Pre-A1 / A1",
    "duration_minutes": 35,
    "is_preset": True,
    "questions": [
        {
            "id": 1,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 1: Listen and match the letters with numbers (Track 1).",
            "audio_url": "/uploads/2026/dethitest/detest-lop2-len-3/track-1-20260819104147-BCOhB.mp3",
            "options": [
                {"key": "A", "text": "1 - A", "image_url": "/uploads/tests/TEST-G2-G3/image1.jpeg"},
                {"key": "B", "text": "1 - B", "image_url": "/uploads/tests/TEST-G2-G3/image2.jpeg"},
                {"key": "C", "text": "1 - C", "image_url": "/uploads/tests/TEST-G2-G3/image3.png"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Key: 1 - A"
        },
        {
            "id": 2,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 2: Listen and tick the box (Track 2). Which is Nick's favorite animal?",
            "audio_url": "/uploads/2026/dethitest/detest-lop2-len-3/track-2-20260819104150-I4TSI.mp3",
            "options": [
                {"key": "A", "text": "Picture A", "image_url": "/uploads/tests/TEST-G2-G3/image4.png"},
                {"key": "B", "text": "Picture B", "image_url": "/uploads/tests/TEST-G2-G3/image5.png"},
                {"key": "C", "text": "Picture C", "image_url": "/uploads/tests/TEST-G2-G3/image6.png"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "Key: Picture B"
        },
        {
            "id": 3,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 3: Listen and circle the correct word (Track 3).",
            "audio_url": "/uploads/2026/dethitest/detest-lop2-len-3/track-3-20260819104152-eP3GR.mp3",
            "options": [
                {"key": "A", "text": "kitchen"},
                {"key": "B", "text": "living room"},
                {"key": "C", "text": "bedroom"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Key: kitchen"
        },
        {
            "id": 4,
            "skill": "grammar",
            "type": "fill_blank",
            "title": "Question 4: Complete the sentence: These _____ (is / are) my new pencils.",
            "correct_answer": "are",
            "points": 1,
            "explanation": "These + are (plural)"
        },
        {
            "id": 5,
            "skill": "grammar",
            "type": "multiple_choice",
            "title": "Question 5: Choose the correct sentence: Where is the cat?",
            "options": [
                {"key": "A", "text": "It is under the table."},
                {"key": "B", "text": "They are under the table."},
                {"key": "C", "text": "It are under the table."}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "It is under the table."
        },
        {
            "id": 6,
            "skill": "reading",
            "type": "true_false",
            "title": "Question 6: Read: 'I have a green school bag with two rulers and five notebooks.' - The school bag is blue.",
            "options": [
                {"key": "A", "text": "Yes (True)"},
                {"key": "B", "text": "No (False)"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "False - The bag is green."
        },
        {
            "id": 7,
            "skill": "writing",
            "type": "essay",
            "title": "Question 7: Write 2 - 3 sentences about your favourite animal or toy.",
            "min_words": 15,
            "points": 5,
            "explanation": "Writing test Grade 2"
        },
        {
            "id": 8,
            "skill": "speaking",
            "type": "speaking_prompt",
            "title": "Question 8: Speaking - Look at the picture and describe what people are doing.",
            "image_url": "/uploads/tests/TEST-G2-G3/image18.png",
            "points": 9,
            "explanation": "Speaking Movers A1"
        }
    ]
})

# 3. TEST-G3-G4 (Lớp 3 lên 4)
tests.append({
    "code": "TEST-G3-G4",
    "title": "Đề Test Đánh Giá Năng Lực Đầu Vào Lớp 3 Lên Lớp 4 (Movers A1)",
    "target_level": "Tiểu học Movers (A1)",
    "duration_minutes": 35,
    "is_preset": True,
    "questions": [
        {
            "id": 1,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 1: Listen and draw lines (Track 1). Which boy is Peter?",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-3-len-4/track-1-1-20260819104634-5RomA.mp3",
            "image_url": "/uploads/tests/TEST-G3-G4/image1.png",
            "options": [
                {"key": "A", "text": "The boy flying a kite"},
                {"key": "B", "text": "The boy riding a red bike"},
                {"key": "C", "text": "The boy sitting on the grass"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "Peter is riding his red bicycle."
        },
        {
            "id": 2,
            "skill": "listening",
            "type": "fill_blank",
            "title": "Question 2: Listen and write words or numbers (Track 2). How old is Daisy?",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-3-len-4/track-2-1-20260819104637-kwJdf.mp3",
            "correct_answer": "9",
            "points": 1,
            "explanation": "Daisy is 9 years old."
        },
        {
            "id": 3,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 3: Listen and tick the box (Track 3). What is Paul doing now?",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-3-len-4/track-3-1-20260819104638-QZ2Ua.mp3",
            "options": [
                {"key": "A", "text": "Playing football", "image_url": "/uploads/tests/TEST-G3-G4/image5.png"},
                {"key": "B", "text": "Reading a comic", "image_url": "/uploads/tests/TEST-G3-G4/image6.png"},
                {"key": "C", "text": "Watching TV", "image_url": "/uploads/tests/TEST-G3-G4/image7.png"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Paul is playing football in the garden."
        },
        {
            "id": 4,
            "skill": "grammar",
            "type": "fill_blank",
            "title": "Question 4: Complete using suitable possessive adjective: I've got blue eyes. _____ eyes are blue.",
            "correct_answer": "My",
            "points": 1,
            "explanation": "I -> My"
        },
        {
            "id": 5,
            "skill": "grammar",
            "type": "fill_blank",
            "title": "Question 5: Complete: He is my brother. _____ name is Brian.",
            "correct_answer": "His",
            "points": 1,
            "explanation": "He -> His"
        },
        {
            "id": 6,
            "skill": "grammar",
            "type": "fill_blank",
            "title": "Question 6: Complete: Anna has a cat. _____ tail is fluffy.",
            "correct_answer": "Its",
            "points": 1,
            "explanation": "Cat -> Its"
        },
        {
            "id": 7,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 7: Choose the best answer: What time _____ you usually go to school?",
            "options": [
                {"key": "A", "text": "do"},
                {"key": "B", "text": "does"},
                {"key": "C", "text": "are"},
                {"key": "D", "text": "is"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Present simple with you: What time do you..."
        },
        {
            "id": 8,
            "skill": "writing",
            "type": "essay",
            "title": "Question 8: Write a short paragraph (30-50 words) about your daily routine (wake up, breakfast, school, bedtime).",
            "min_words": 30,
            "points": 9,
            "explanation": "Writing Movers A1"
        }
    ]
})

# 4. TEST-G4-G5 (Lớp 4 lên 5)
tests.append({
    "code": "TEST-G4-G5",
    "title": "Đề Test Đánh Giá Năng Lực Đầu Vào Lớp 4 Lên Lớp 5 (Movers - Flyers A1/A2)",
    "target_level": "Tiểu học Movers - Flyers (A1 - A2)",
    "duration_minutes": 35,
    "is_preset": True,
    "questions": [
        {
            "id": 1,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 1: Listen and match the person with activity (Track 1).",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-4-len-5/track-1-2-20260819104734-NCtHM.mp3",
            "image_url": "/uploads/tests/TEST-G4-G5/image1.png",
            "options": [
                {"key": "A", "text": "Vicky - swimming in the lake"},
                {"key": "B", "text": "Fred - climbing the tree"},
                {"key": "C", "text": "Jane - cooking sausages on the fire"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Key: Vicky is swimming."
        },
        {
            "id": 2,
            "skill": "listening",
            "type": "fill_blank",
            "title": "Question 2: Listen and write (Track 2). On Friday afternoon they will go to _____.",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-4-len-5/track-2-2-20260819104736-vRlgt.mp3",
            "correct_answer": "the zoo",
            "points": 1,
            "explanation": "Friday afternoon: to the zoo"
        },
        {
            "id": 3,
            "skill": "listening",
            "type": "fill_blank",
            "title": "Question 3: Listen and write (Track 2). On Saturday afternoon they will go to _____.",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-4-len-5/track-2-2-20260819104736-vRlgt.mp3",
            "correct_answer": "a cave",
            "points": 1,
            "explanation": "Saturday afternoon: a cave"
        },
        {
            "id": 4,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 4: Listen and circle picture (Track 3). What is next to the museum?",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-4-len-5/track-3-2-20260819104738-eIS3y.mp3",
            "options": [
                {"key": "A", "text": "Picture A", "image_url": "/uploads/tests/TEST-G4-G5/image3.png"},
                {"key": "B", "text": "Picture B", "image_url": "/uploads/tests/TEST-G4-G5/image4.png"},
                {"key": "C", "text": "Picture C", "image_url": "/uploads/tests/TEST-G4-G5/image5.png"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "Key: Picture B"
        },
        {
            "id": 5,
            "skill": "grammar",
            "type": "multiple_choice",
            "title": "Question 5: Yesterday, my father _____ a delicious dinner for our whole family.",
            "options": [
                {"key": "A", "text": "cooks"},
                {"key": "B", "text": "cooked"},
                {"key": "C", "text": "cook"},
                {"key": "D", "text": "was cooked"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "Past simple tense with 'Yesterday': cooked"
        },
        {
            "id": 6,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 6: Which is correct comparative? A cheetah runs _____ than an elephant.",
            "options": [
                {"key": "A", "text": "faster"},
                {"key": "B", "text": "more fast"},
                {"key": "C", "text": "fastest"},
                {"key": "D", "text": "as fast"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Short adjective: fast -> faster"
        },
        {
            "id": 7,
            "skill": "writing",
            "type": "essay",
            "title": "Question 7: Write a short story or paragraph (50-80 words) about your last weekend trip with family.",
            "min_words": 50,
            "points": 9,
            "explanation": "Flyers A2 Writing"
        }
    ]
})

# 5. TEST-G5-G6-CB (Lớp 5 lên 6 Cơ Bản)
tests.append({
    "code": "TEST-G5-G6-CB",
    "title": "Đề Test Đánh Giá Năng Lực Đầu Vào Lớp 5 Lên Lớp 6 - Level Cơ Bản (Flyers A2)",
    "target_level": "Chuyển cấp Tiểu học - THCS Cơ bản (A2)",
    "duration_minutes": 35,
    "is_preset": True,
    "questions": [
        {
            "id": 1,
            "skill": "listening",
            "type": "fill_blank",
            "title": "Question 1: Listen and write words (Track 1). What is his name? (Peter _____)",
            "audio_url": "/uploads/2026/dethitest/de-thi-test-5-len-6/level-co-ban/track-1-3-20260819104850-Fseal.mp3",
            "correct_answer": "Moon",
            "points": 1,
            "explanation": "Peter Moon"
        },
        {
            "id": 2,
            "skill": "listening",
            "type": "fill_blank",
            "title": "Question 2: Listen and write (Track 1). What is his age?",
            "audio_url": "/uploads/2026/dethitest/de-thi-test-5-len-6/level-co-ban/track-1-3-20260819104850-Fseal.mp3",
            "correct_answer": "10",
            "points": 1,
            "explanation": "Age: 10"
        },
        {
            "id": 3,
            "skill": "listening",
            "type": "fill_blank",
            "title": "Question 3: Listen and write (Track 1). Sport he wants to play: _____",
            "audio_url": "/uploads/2026/dethitest/de-thi-test-5-len-6/level-co-ban/track-1-3-20260819104850-Fseal.mp3",
            "correct_answer": "baseball",
            "points": 1,
            "explanation": "baseball"
        },
        {
            "id": 4,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 4: Listen and circle picture (Track 2). What does Helen play at the club?",
            "audio_url": "/uploads/2026/dethitest/de-thi-test-5-len-6/level-co-ban/track-2-3-20260819104851-Q9IIv.mp3",
            "options": [
                {"key": "A", "text": "Picture A", "image_url": "/uploads/tests/TEST-G5-G6-CB/image1.png"},
                {"key": "B", "text": "Picture B", "image_url": "/uploads/tests/TEST-G5-G6-CB/image2.png"},
                {"key": "C", "text": "Picture C", "image_url": "/uploads/tests/TEST-G5-G6-CB/image3.png"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Helen plays badminton/table tennis"
        },
        {
            "id": 5,
            "skill": "grammar",
            "type": "multiple_choice",
            "title": "Question 5: They _____ to the library every Wednesday afternoon.",
            "options": [
                {"key": "A", "text": "goes"},
                {"key": "B", "text": "go"},
                {"key": "C", "text": "going"},
                {"key": "D", "text": "are go"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "Present simple with plural subject 'They': go"
        },
        {
            "id": 6,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 6: My sister is interested _____ learning foreign languages.",
            "options": [
                {"key": "A", "text": "in"},
                {"key": "B", "text": "on"},
                {"key": "C", "text": "at"},
                {"key": "D", "text": "for"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Collocation: interested in + V-ing"
        },
        {
            "id": 7,
            "skill": "writing",
            "type": "essay",
            "title": "Question 7: Write a short paragraph (60-80 words) describing your dream school.",
            "min_words": 60,
            "points": 9,
            "explanation": "Writing Grade 5-6"
        }
    ]
})

# 6. TEST-G5-G6-NC (Lớp 5 lên 6 Nâng Cao)
tests.append({
    "code": "TEST-G5-G6-NC",
    "title": "Đề Test Đánh Giá Năng Lực Đầu Vào Lớp 5 Lên Lớp 6 - Level Nâng Cao (KET A2-B1)",
    "target_level": "Chuyển cấp Tiểu học - THCS Nâng cao (A2 - B1)",
    "duration_minutes": 40,
    "is_preset": True,
    "questions": [
        {
            "id": 1,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 1: Choose the word which has the underlined part pronounced differently.",
            "options": [
                {"key": "A", "text": "square"},
                {"key": "B", "text": "badminton"},
                {"key": "C", "text": "grandfather"},
                {"key": "D", "text": "match"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "square is pronounced /eə/, others are /æ/"
        },
        {
            "id": 2,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 2: Choose the word which has the underlined part pronounced differently.",
            "options": [
                {"key": "A", "text": "easy"},
                {"key": "B", "text": "please"},
                {"key": "C", "text": "weak"},
                {"key": "D", "text": "pear"}
            ],
            "correct_answer": "D",
            "points": 1,
            "explanation": "pear is pronounced /peə/, others are /i:/"
        },
        {
            "id": 3,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 3: Find the word which is not the same with the others in a group.",
            "options": [
                {"key": "A", "text": "basketball"},
                {"key": "B", "text": "volleyball"},
                {"key": "C", "text": "football"},
                {"key": "D", "text": "chess"}
            ],
            "correct_answer": "D",
            "points": 1,
            "explanation": "chess is a board game, others are ball sports"
        },
        {
            "id": 4,
            "skill": "grammar",
            "type": "multiple_choice",
            "title": "Question 4: What do students often do _____ break?",
            "options": [
                {"key": "A", "text": "in"},
                {"key": "B", "text": "for"},
                {"key": "C", "text": "at"},
                {"key": "D", "text": "on"}
            ],
            "correct_answer": "C",
            "points": 1,
            "explanation": "at break / at break time"
        },
        {
            "id": 5,
            "skill": "grammar",
            "type": "multiple_choice",
            "title": "Question 5: Would you like _____ that for you?",
            "options": [
                {"key": "A", "text": "me doing"},
                {"key": "B", "text": "that I do"},
                {"key": "C", "text": "me do"},
                {"key": "D", "text": "me to do"}
            ],
            "correct_answer": "D",
            "points": 1,
            "explanation": "would you like someone to do something"
        },
        {
            "id": 6,
            "skill": "grammar",
            "type": "multiple_choice",
            "title": "Question 6: There _____ four chairs and a table _____ the middle of the room.",
            "options": [
                {"key": "A", "text": "are / in"},
                {"key": "B", "text": "are / at"},
                {"key": "C", "text": "is / on"},
                {"key": "D", "text": "is / in"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "There are four chairs... in the middle of the room."
        },
        {
            "id": 7,
            "skill": "writing",
            "type": "essay",
            "title": "Question 7: Writing Task - Write a letter (80-100 words) inviting your friend to visit your hometown.",
            "min_words": 80,
            "points": 9,
            "explanation": "KET A2 Writing"
        }
    ]
})

# 7. TEST-G6-G7 (Lớp 6 lên 7)
tests.append({
    "code": "TEST-G6-G7",
    "title": "Đề Test Đánh Giá Năng Lực Đầu Vào Lớp 6 Lên Lớp 7 (THCS A2 - B1)",
    "target_level": "THCS Lớp 6 lên 7 (A2 - B1)",
    "duration_minutes": 45,
    "is_preset": True,
    "questions": [
        {
            "id": 1,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 1: Listen to Peter talking about his dream house (Track 1). How will Peter move through his house?",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-6-len-7/track-1-4-20260819105025-7k9aa.mp3",
            "options": [
                {"key": "A", "text": "On foot."},
                {"key": "B", "text": "By car."},
                {"key": "C", "text": "By train."}
            ],
            "correct_answer": "C",
            "points": 1,
            "explanation": "Peter says he will move by train."
        },
        {
            "id": 2,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 2: What will Peter do in his living room?",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-6-len-7/track-1-4-20260819105025-7k9aa.mp3",
            "options": [
                {"key": "A", "text": "Watch films."},
                {"key": "B", "text": "Play computer games."},
                {"key": "C", "text": "Sing a song."}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "He will watch films."
        },
        {
            "id": 3,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 3: How many swimming pools will there be in Peter's house?",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-6-len-7/track-1-4-20260819105025-7k9aa.mp3",
            "options": [
                {"key": "A", "text": "One."},
                {"key": "B", "text": "Two."},
                {"key": "C", "text": "Three."}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "Two swimming pools."
        },
        {
            "id": 4,
            "skill": "listening",
            "type": "fill_blank",
            "title": "Question 4: Listen to 3Rs tips (Track 2). Reuse - use _____ of the paper.",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-6-len-7/track-2-4-20260819105033-JRlN1.mp3",
            "correct_answer": "both sides",
            "points": 1,
            "explanation": "use both sides of the paper"
        },
        {
            "id": 5,
            "skill": "grammar",
            "type": "multiple_choice",
            "title": "Question 5: If we plant more trees in our school yard, the environment _____ greener.",
            "options": [
                {"key": "A", "text": "is"},
                {"key": "B", "text": "will be"},
                {"key": "C", "text": "was"},
                {"key": "D", "text": "would be"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "First conditional: If + Present Simple, will + V"
        },
        {
            "id": 6,
            "skill": "grammar",
            "type": "fill_blank",
            "title": "Question 6: Rewrite sentence: My sightseeing tour in Melbourne lasted two hours. (GOING) -> I spent two hours _____ on my sightseeing tour in Melbourne.",
            "correct_answer": "going",
            "points": 1,
            "explanation": "spend + time + V-ing: going"
        },
        {
            "id": 7,
            "skill": "writing",
            "type": "essay",
            "title": "Question 7: Write a short paragraph (80-100 words) about things we should do to protect the local environment (Reduce, Reuse, Recycle).",
            "min_words": 80,
            "points": 9,
            "explanation": "Writing Grade 6-7"
        }
    ]
})

# 8. TEST-G7-G8 (Lớp 7 lên 8)
tests.append({
    "code": "TEST-G7-G8",
    "title": "Đề Test Đánh Giá Năng Lực Đầu Vào Lớp 7 Lên Lớp 8 (THCS B1)",
    "target_level": "THCS Lớp 7 lên 8 (B1)",
    "duration_minutes": 45,
    "is_preset": True,
    "questions": [
        {
            "id": 1,
            "skill": "listening",
            "type": "true_false",
            "title": "Question 1: Listen to Track 1. Statement: 'There are more and more people on the earth.' (True or False)",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-7-len-8/track-1-5-20260819105134-8xQj9.mp3",
            "options": [
                {"key": "A", "text": "True"},
                {"key": "B", "text": "False"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "Key: TRUE"
        },
        {
            "id": 2,
            "skill": "listening",
            "type": "true_false",
            "title": "Question 2: Listen to Track 1. Statement: 'The author thinks that it is totally bad for the population to continue to increase.'",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-7-len-8/track-1-5-20260819105134-8xQj9.mp3",
            "options": [
                {"key": "A", "text": "True"},
                {"key": "B", "text": "False"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "Key: FALSE"
        },
        {
            "id": 3,
            "skill": "listening",
            "type": "multiple_choice",
            "title": "Question 3: Listen and choose the correct picture (Track 2). What is John going to do tonight?",
            "audio_url": "/uploads/2026/dethitest/de-test-lop-7-len-8/track-2-5-20260819105135-kxR9Y.mp3",
            "options": [
                {"key": "A", "text": "Picture A", "image_url": "/uploads/tests/TEST-G7-G8/image1.png"},
                {"key": "B", "text": "Picture B", "image_url": "/uploads/tests/TEST-G7-G8/image2.png"},
                {"key": "C", "text": "Picture C", "image_url": "/uploads/tests/TEST-G7-G8/image3.png"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "Key: Picture B"
        },
        {
            "id": 4,
            "skill": "grammar",
            "type": "multiple_choice",
            "title": "Question 4: Although he was tired, _____ he tried to finish his homework before midnight.",
            "options": [
                {"key": "A", "text": "but"},
                {"key": "B", "text": "yet"},
                {"key": "C", "text": "so"},
                {"key": "D", "text": "(no word needed)"}
            ],
            "correct_answer": "D",
            "points": 1,
            "explanation": "Although already connects the clauses, no 'but' is used in English."
        },
        {
            "id": 5,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 5: Renewable energy sources emit _____ greenhouse gases than coal power plants.",
            "options": [
                {"key": "A", "text": "much fewer"},
                {"key": "B", "text": "far less"},
                {"key": "C", "text": "more little"},
                {"key": "D", "text": "lesser"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "far less + uncountable noun (gas)"
        },
        {
            "id": 6,
            "skill": "writing",
            "type": "essay",
            "title": "Question 6: Writing Task - Write a short paragraph (100-120 words) discussing the advantages and disadvantages of using smartphones for studying.",
            "min_words": 100,
            "points": 9,
            "explanation": "Writing Grade 7-8"
        }
    ]
})

# 9. TEST-G8-G9 (Lớp 8 lên 9)
tests.append({
    "code": "TEST-G8-G9",
    "title": "Đề Test Đánh Giá Năng Lực Đầu Vào Lớp 8 Lên Lớp 9 - Tuyển Sinh Vào 10 (B1 - B2)",
    "target_level": "THCS Lớp 8 lên 9 & Ôn thi Vào 10 (B1 - B2)",
    "duration_minutes": 45,
    "is_preset": True,
    "questions": [
        {
            "id": 1,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 1: Choose the word whose underlined part is pronounced differently.",
            "options": [
                {"key": "A", "text": "leaves"},
                {"key": "B", "text": "songs"},
                {"key": "C", "text": "deserts"},
                {"key": "D", "text": "knives"}
            ],
            "correct_answer": "C",
            "points": 1,
            "explanation": "deserts ends in /s/, others end in /z/"
        },
        {
            "id": 2,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 2: Choose the word that differs in the position of main stress.",
            "options": [
                {"key": "A", "text": "question"},
                {"key": "B", "text": "investigate"},
                {"key": "C", "text": "motorcycle"},
                {"key": "D", "text": "notice"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "investigate is stressed on the 2nd syllable, others on 1st"
        },
        {
            "id": 3,
            "skill": "grammar",
            "type": "multiple_choice",
            "title": "Question 3: Mary _____ the country by the time this letter reaches her.",
            "options": [
                {"key": "A", "text": "is going to leave"},
                {"key": "B", "text": "will leave"},
                {"key": "C", "text": "is leaving"},
                {"key": "D", "text": "will have left"}
            ],
            "correct_answer": "D",
            "points": 1,
            "explanation": "Future perfect: by the time + Present Simple, will have + V3"
        },
        {
            "id": 4,
            "skill": "grammar",
            "type": "multiple_choice",
            "title": "Question 4: Most Americans don't object _____ being called by their first names.",
            "options": [
                {"key": "A", "text": "for"},
                {"key": "B", "text": "to"},
                {"key": "C", "text": "in"},
                {"key": "D", "text": "about"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "object to + V-ing"
        },
        {
            "id": 5,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 5: Choose the word CLOSEST in meaning to the underlined word: 'I only have time to tell you the main idea of it, not the details.'",
            "options": [
                {"key": "A", "text": "story"},
                {"key": "B", "text": "gist"},
                {"key": "C", "text": "list"},
                {"key": "D", "text": "start"}
            ],
            "correct_answer": "B",
            "points": 1,
            "explanation": "main idea = gist"
        },
        {
            "id": 6,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 6: Choose the word OPPOSITE in meaning to the underlined word: 'My mother often tells me never to accept a lift from someone I've just met!'",
            "options": [
                {"key": "A", "text": "assist"},
                {"key": "B", "text": "deny"},
                {"key": "C", "text": "refuse"},
                {"key": "D", "text": "prevent"}
            ],
            "correct_answer": "C",
            "points": 1,
            "explanation": "accept (chấp nhận) <-> refuse (từ chối)"
        },
        {
            "id": 7,
            "skill": "reading",
            "type": "multiple_choice",
            "title": "Question 7: Reading Passage: The relationship between students and teachers is less formal in the USA than in many other countries... American students do not stand up (1) _____ their teachers enter the room.",
            "options": [
                {"key": "A", "text": "when"},
                {"key": "B", "text": "where"},
                {"key": "C", "text": "that"},
                {"key": "D", "text": "whether"}
            ],
            "correct_answer": "A",
            "points": 1,
            "explanation": "when their teachers enter the room"
        },
        {
            "id": 8,
            "skill": "grammar",
            "type": "fill_blank",
            "title": "Question 8: Rewrite sentence: They often went to school by bike when they were young. -> They used _____ to school by bike when they were young.",
            "correct_answer": "to go",
            "points": 1,
            "explanation": "used to go"
        },
        {
            "id": 9,
            "skill": "grammar",
            "type": "fill_blank",
            "title": "Question 9: Rewrite sentence: It took me 4 hours to read the first chapter of the book. (SPENT) -> I spent 4 hours _____ the first chapter of the book.",
            "correct_answer": "reading",
            "points": 1,
            "explanation": "spend + time + V-ing: reading"
        },
        {
            "id": 10,
            "skill": "writing",
            "type": "essay",
            "title": "Question 10: Writing Task - Some people believe that traditional face-to-face classes are better than online learning. Discuss both views and give your opinion (120-150 words).",
            "min_words": 120,
            "points": 9,
            "explanation": "IELTS / Grade 9 Exam Writing Task"
        }
    ]
})

# Generate PHP Seeder Content
tests_json = json.dumps(tests, ensure_ascii=False, indent=8)

php_code = f"""<?php

namespace Database\\Seeders;

use App\\Models\\PlacementTest;
use Illuminate\\Database\\Seeder;

class GradeTestsSeeder extends Seeder
{{
    public function run(): void
    {{
        $testsJson = <<<'JSON'
{tests_json}
JSON;

        $tests = json_decode($testsJson, true);

        foreach ($tests as $t) {{
            PlacementTest::query()->updateOrCreate(
                ['code' => $t['code']],
                [
                    'title' => $t['title'],
                    'target_level' => $t['target_level'],
                    'duration_minutes' => $t['duration_minutes'],
                    'questions_count' => count($t['questions']),
                    'questions' => $t['questions'],
                    'is_active' => true,
                    'is_preset' => true,
                ]
            );
        }}
    }}
}}
"""

with open('database/seeders/GradeTestsSeeder.php', 'w', encoding='utf-8') as f:
    f.write(php_code)

print("Generated database/seeders/GradeTestsSeeder.php successfully with 9 full tests!")
