"""Curated public-domain hymn library (Open Hymnal Project).

This is the single source of truth shared by two callers:

  * seed_hymns.py        — renders each hymn's MIDI to an MP3 and stores it under
                           the `hymns/` key, once per machine (like a DB seed).
  * HymnStrategy.fetch()  — at service time, picks a mood-appropriate hymn and
                           hands back the already-stored MP3. No network, no AI
                           credit: the audio was produced ahead of time.

Every hymn below is firmly in the public domain (text and tune), and its `midi`
filename exists verbatim in Open Hymnal's `OpenHymnal2014.06-midi.zip`. `moods`
are lowercase trigger words matched against the worshipper's mood/prompt; a hymn
tagged "default" is eligible when nothing else matches, so selection never fails.
"""

from __future__ import annotations

import random

# slug, title, author, year, scripture, midi (Open Hymnal filename), moods
HYMNS: list[dict] = [
    {
        "slug": "amazing-grace", "title": "Amazing Grace", "author": "John Newton",
        "year": 1779, "scripture": "Ephesians 2:8", "midi": "Amazing_Grace-New_Britain.mid",
        "moods": {"grateful", "seeking", "hope", "hopeful", "grace", "default"},
    },
    {
        "slug": "it-is-well", "title": "It Is Well With My Soul", "author": "Horatio Spafford",
        "year": 1873, "scripture": "Psalm 46:1", "midi": "It_Is_Well_With_My_Soul-It_Is_Well-Ville_Du_Havre.mid",
        "moods": {"grieving", "anxious", "peace", "comfort", "loss"},
        # LRC pilot fixture (public-domain verse 1 + refrain). The `timings`
        # below are APPROXIMATE placeholders spread across the 78rpm recording's
        # ~176s so the synced-lyrics path can be exercised end-to-end; re-author
        # accurate cues with `python workers/tools/tap_lyrics.py` against the real
        # audio when piloting for real.
        "lyrics": (
            "When peace like a river attendeth my way,\n"
            "When sorrows like sea billows roll;\n"
            "Whatever my lot, Thou hast taught me to say,\n"
            "It is well, it is well with my soul.\n"
            "It is well with my soul,\n"
            "It is well, it is well with my soul."
        ),
        "timings": [
            {"time": 6.0,   "line_index": 0},
            {"time": 24.0,  "line_index": 1},
            {"time": 42.0,  "line_index": 2},
            {"time": 60.0,  "line_index": 3},
            {"time": 82.0,  "line_index": 4},
            {"time": 100.0, "line_index": 5},
        ],
    },
    {
        "slug": "be-still-my-soul", "title": "Be Still, My Soul", "author": "Katharina von Schlegel",
        "year": 1752, "scripture": "Psalm 46:10", "midi": "Be_Still_My_Soul-Finlandia.mid",
        "moods": {"anxious", "grieving", "peace", "comfort", "fear"},
    },
    {
        "slug": "abide-with-me", "title": "Abide With Me", "author": "Henry F. Lyte",
        "year": 1847, "scripture": "Luke 24:29", "midi": "Abide_With_Me-Eventide.mid",
        "moods": {"grieving", "comfort", "loss", "lonely"},
    },
    {
        "slug": "nearer-my-god", "title": "Nearer, My God, to Thee", "author": "Sarah F. Adams",
        "year": 1841, "scripture": "Genesis 28:12", "midi": "Nearer_My_God_To_Thee-Bethany.mid",
        "moods": {"grieving", "seeking", "comfort"},
    },
    {
        "slug": "come-ye-disconsolate", "title": "Come, Ye Disconsolate", "author": "Thomas Moore",
        "year": 1816, "scripture": "Psalm 34:18", "midi": "Come_Ye_Disconsolate-Consolator_Webbe.mid",
        "moods": {"grieving", "anxious", "comfort", "loss"},
    },
    {
        "slug": "what-a-friend", "title": "What a Friend We Have in Jesus", "author": "Joseph M. Scriven",
        "year": 1855, "scripture": "1 Peter 5:7", "midi": "What_A_Friend_We_Have_In_Jesus-untitled.mid",
        "moods": {"anxious", "grieving", "comfort", "prayer", "lonely"},
    },
    {
        "slug": "guide-me", "title": "Guide Me, O Thou Great Jehovah", "author": "William Williams",
        "year": 1745, "scripture": "Exodus 13:21", "midi": "Guide_Me_O_Thou_Great_Jehovah-Cwm_Rhondda.mid",
        "moods": {"anxious", "seeking", "hope", "hopeful", "journey"},
    },
    {
        "slug": "how-firm-a-foundation", "title": "How Firm a Foundation", "author": "John Rippon's Selection",
        "year": 1787, "scripture": "Isaiah 41:10", "midi": "How_Firm_A_Foundation-Foundation-Protection.mid",
        "moods": {"anxious", "hope", "hopeful", "fear", "assurance"},
    },
    {
        "slug": "my-hope-is-built", "title": "My Hope Is Built (The Solid Rock)", "author": "Edward Mote",
        "year": 1834, "scripture": "Matthew 7:24", "midi": "My_Hope_Is_Built-Melita.mid",
        "moods": {"hope", "hopeful", "anxious", "assurance"},
    },
    {
        "slug": "joyful-joyful", "title": "Joyful, Joyful, We Adore Thee", "author": "Henry van Dyke",
        "year": 1907, "scripture": "Psalm 98:4", "midi": "Joyful_Joyful_We_Adore_Thee-Ode_To_Joy.mid",
        "moods": {"joyful", "grateful", "praise", "joy", "happy"},
    },
    {
        "slug": "now-thank-we", "title": "Now Thank We All Our God", "author": "Martin Rinkart",
        "year": 1636, "scripture": "1 Thessalonians 5:18", "midi": "Now_Thank_We_All_Our_God-Nun_Danket.mid",
        "moods": {"grateful", "joyful", "thanks", "thankful", "praise"},
    },
    {
        "slug": "praise-to-the-lord", "title": "Praise to the Lord, the Almighty", "author": "Joachim Neander",
        "year": 1680, "scripture": "Psalm 103:1", "midi": "Praise_To_The_Lord_The_Almighty-Lobe_Den_Herren.mid",
        "moods": {"grateful", "joyful", "praise"},
    },
    {
        "slug": "all-creatures", "title": "All Creatures of Our God and King", "author": "St. Francis of Assisi",
        "year": 1225, "scripture": "Psalm 148", "midi": "All_Creatures_Of_Our_God_And_King-Lasst_Uns_Erfreuen.mid",
        "moods": {"joyful", "grateful", "praise"},
    },
    {
        "slug": "to-god-be-the-glory", "title": "To God Be the Glory", "author": "Fanny J. Crosby",
        "year": 1875, "scripture": "John 3:16", "midi": "To_God_Be_the_Glory-To_God_Be_the_Glory.mid",
        "moods": {"joyful", "grateful", "praise"},
    },
    {
        "slug": "holy-holy-holy", "title": "Holy, Holy, Holy", "author": "Reginald Heber",
        "year": 1826, "scripture": "Revelation 4:8", "midi": "Holy_Holy_Holy-Nicaea.mid",
        "moods": {"praise", "reverence", "default"},
    },
    {
        "slug": "crown-him", "title": "Crown Him with Many Crowns", "author": "Matthew Bridges",
        "year": 1851, "scripture": "Revelation 19:12", "midi": "Crown_Him_With_Many_Crowns-Diademata.mid",
        "moods": {"joyful", "praise"},
    },
    {
        "slug": "be-thou-my-vision", "title": "Be Thou My Vision", "author": "Irish hymn, tr. Mary E. Byrne",
        "year": 1905, "scripture": "Proverbs 3:5", "midi": "Be_Thou_My_Vision-Slane.mid",
        "moods": {"seeking", "hope", "hopeful", "devotion", "default"},
    },
    {
        "slug": "come-thou-fount", "title": "Come, Thou Fount of Every Blessing", "author": "Robert Robinson",
        "year": 1758, "scripture": "1 Samuel 7:12", "midi": "Come_Thou_Fount-Nettleton.mid",
        "moods": {"grateful", "seeking", "default"},
    },
    {
        "slug": "blessed-assurance", "title": "Blessed Assurance", "author": "Fanny J. Crosby",
        "year": 1873, "scripture": "Hebrews 10:22", "midi": "Blessed_Assurance-Blessed_Assurance-Assurance.mid",
        "moods": {"hope", "hopeful", "joyful", "assurance", "grateful"},
    },
    {
        "slug": "rock-of-ages", "title": "Rock of Ages", "author": "Augustus M. Toplady",
        "year": 1763, "scripture": "Psalm 94:22", "midi": "Rock_of_Ages-Toplady.mid",
        "moods": {"seeking", "hope", "refuge", "anxious"},
    },
    {
        "slug": "pass-me-not", "title": "Pass Me Not, O Gentle Savior", "author": "Fanny J. Crosby",
        "year": 1868, "scripture": "Luke 18:38", "midi": "Pass_Me_Not_O_Gentle_Savior-Pass_Me_Not_O_Gentle_Savior.mid",
        "moods": {"seeking", "grieving", "prayer"},
    },
    {
        "slug": "i-need-thee", "title": "I Need Thee Every Hour", "author": "Annie S. Hawks",
        "year": 1872, "scripture": "John 15:5", "midi": "I_Need_Thee_Every_Hour-I_Need_Thee_Every_Hour.mid",
        "moods": {"seeking", "anxious", "prayer", "default"},
    },

    # === CHRISTMAS & ADVENT ===
    {
        "slug": "joy-to-the-world", "title": "Joy to the World",
        "author": "Isaac Watts", "year": 1719, "scripture": "Psalm 98:4",
        "midi": "Joy_To_The_World-Antioch.mid",
        "moods": {"joyful", "christmas", "praise", "happy", "grateful"},
    },
    {
        "slug": "hark-the-herald", "title": "Hark! The Herald Angels Sing",
        "author": "Charles Wesley", "year": 1739, "scripture": "Luke 2:14",
        "midi": "Hark_The_Herald_Angels_Sing-Mendelssohn.mid",
        "moods": {"joyful", "christmas", "praise"},
    },
    {
        "slug": "o-come-all-ye-faithful", "title": "O Come, All Ye Faithful",
        "author": "John Francis Wade", "year": 1751, "scripture": "Luke 2:15",
        "midi": "O_Come_All_Ye_Faithful-Adeste_Fideles-Portugese_Hymn.mid",
        "moods": {"joyful", "christmas", "praise", "default"},
    },
    {
        "slug": "o-come-o-come-emmanuel", "title": "O Come, O Come, Emmanuel",
        "author": "Latin, tr. John M. Neale", "year": 1851, "scripture": "Isaiah 7:14",
        "midi": "O_Come_O_Come_Emmanuel-Veni_Emmanuel.mid",
        "moods": {"advent", "seeking", "hope", "longing"},
    },
    {
        "slug": "silent-night", "title": "Silent Night",
        "author": "Joseph Mohr", "year": 1818, "scripture": "Luke 2:7",
        "midi": "Silent_Night-Stille_Nacht.mid",
        "moods": {"peace", "christmas", "comfort", "reverence"},
    },
    {
        "slug": "away-in-a-manger", "title": "Away in a Manger",
        "author": "Anonymous", "year": 1885, "scripture": "Luke 2:7",
        "midi": "Away_In_A_Manger-Mueller.mid",
        "moods": {"christmas", "comfort", "peace"},
    },
    {
        "slug": "o-little-town", "title": "O Little Town of Bethlehem",
        "author": "Phillips Brooks", "year": 1868, "scripture": "Micah 5:2",
        "midi": "O_Little_Town_Of_Bethlehem-St_Louis.mid",
        "moods": {"christmas", "peace", "comfort", "hope"},
    },
    {
        "slug": "the-first-noel", "title": "The First Noel",
        "author": "Traditional English", "year": 1823, "scripture": "Luke 2:8",
        "midi": "The_First_Noel-The_First_Noel.mid",
        "moods": {"christmas", "joyful", "praise"},
    },
    {
        "slug": "it-came-upon-a-midnight", "title": "It Came Upon the Midnight Clear",
        "author": "Edmund H. Sears", "year": 1849, "scripture": "Luke 2:13",
        "midi": "It_Came_Upon_A_Midnight_Clear-Carol.mid",
        "moods": {"christmas", "peace", "hope"},
    },
    {
        "slug": "what-child-is-this", "title": "What Child Is This?",
        "author": "William C. Dix", "year": 1865, "scripture": "Luke 2:12",
        "midi": "What_Child_Is_This-Greensleeves.mid",
        "moods": {"christmas", "reverence", "wonder"},
    },
    {
        "slug": "lo-how-a-rose", "title": "Lo, How a Rose E'er Blooming",
        "author": "German carol, tr. Theodore Baker", "year": 1582, "scripture": "Isaiah 11:1",
        "midi": "Lo_How_A_Rose_Eer_Blooming-Es_Ist_Ein_Ros_Entsprungen.mid",
        "moods": {"christmas", "peace", "hope", "reverence"},
    },
    {
        "slug": "come-thou-long-expected", "title": "Come, Thou Long-Expected Jesus",
        "author": "Charles Wesley", "year": 1744, "scripture": "Luke 2:25",
        "midi": "Come_Thou_Long_Expected_Jesus-Jefferson.mid",
        "moods": {"advent", "hope", "hopeful", "longing", "seeking"},
    },
    {
        "slug": "wake-awake", "title": "Wake, Awake, for Night Is Flying",
        "author": "Philipp Nicolai", "year": 1599, "scripture": "Matthew 25:6",
        "midi": "Wake_Awake_For_Night_Is_Flying-Wachet_Auf.mid",
        "moods": {"advent", "hope", "coming"},
    },
    {
        "slug": "angels-we-have-heard", "title": "Angels We Have Heard on High",
        "author": "French carol", "year": 1862, "scripture": "Luke 2:13",
        "midi": "Angels_We_Have_Heard_On_High-Gloria.mid",
        "moods": {"christmas", "joyful", "praise"},
    },
    {
        "slug": "hark-the-glad-sound", "title": "Hark! The Glad Sound",
        "author": "Philip Doddridge", "year": 1735, "scripture": "Luke 4:18",
        "midi": "Hark_The_Glad_Sound-Chesterfield-Richmond_Haweis-Spa_Fields_Chapel.mid",
        "moods": {"advent", "hope", "joyful", "praise"},
    },
    {
        "slug": "in-the-bleak-midwinter", "title": "In the Bleak Midwinter",
        "author": "Christina Rossetti", "year": 1906, "scripture": "John 1:14",
        "midi": "In_The_Bleak_MidWinter-Cranham.mid",
        "moods": {"christmas", "reverence", "devotion", "wonder"},
    },
    {
        "slug": "of-the-fathers-love", "title": "Of the Father's Love Begotten",
        "author": "Aurelius Prudentius", "year": 413, "scripture": "John 1:14",
        "midi": "Of_The_Fathers_Love_Begotten-Divinum_Mysterium-Corde_Natus.mid",
        "moods": {"christmas", "reverence", "praise", "worship"},
    },
    {
        "slug": "lo-he-comes-on-clouds", "title": "Lo, He Comes on Clouds Descending",
        "author": "Charles Wesley", "year": 1758, "scripture": "Revelation 1:7",
        "midi": "Lo_He_Comes_On_Clouds_Descending-Helmsley.mid",
        "moods": {"advent", "hope", "coming", "reverence"},
    },

    # === EASTER & RESURRECTION ===
    {
        "slug": "christ-the-lord-is-risen", "title": "Christ the Lord Is Risen Today",
        "author": "Charles Wesley", "year": 1739, "scripture": "Matthew 28:6",
        "midi": "Christ_The_Lord_Is_Risen_Today-Llanfair.mid",
        "moods": {"joyful", "resurrection", "easter", "praise", "victory"},
    },
    {
        "slug": "christ-arose", "title": "Christ Arose (Low in the Grave He Lay)",
        "author": "Robert Lowry", "year": 1874, "scripture": "John 20:1",
        "midi": "Christ_Arose-Christ_Arose.mid",
        "moods": {"resurrection", "easter", "hope", "joyful", "victory"},
    },
    {
        "slug": "jesus-christ-is-risen", "title": "Jesus Christ Is Risen Today",
        "author": "Latin, tr. John Walsh", "year": 1708, "scripture": "1 Corinthians 15:4",
        "midi": "Jesus_Christ_Is_Risen_Today-Easter_Hymn.mid",
        "moods": {"resurrection", "easter", "joyful", "praise", "victory"},
    },
    {
        "slug": "the-strife-is-oer", "title": "The Strife Is O'er, the Battle Done",
        "author": "Latin, tr. Francis Pott", "year": 1695, "scripture": "1 Corinthians 15:55",
        "midi": "The_Strife_Is_Oer_The_Battle_Done-Victory-Palestrina.mid",
        "moods": {"resurrection", "easter", "victory", "joyful"},
    },
    {
        "slug": "i-know-redeemer-lives", "title": "I Know That My Redeemer Lives",
        "author": "Samuel Medley", "year": 1775, "scripture": "Job 19:25",
        "midi": "I_Know_That_My_Redeemer_Lives-Duke_Street.mid",
        "moods": {"hope", "resurrection", "assurance", "joyful", "comfort"},
    },

    # === PRAISE & WORSHIP ===
    {
        "slug": "a-mighty-fortress", "title": "A Mighty Fortress Is Our God",
        "author": "Martin Luther", "year": 1529, "scripture": "Psalm 46:1",
        "midi": "A_Mighty_Fortress_Is_Our_God-Ein_Feste_Burg_Rhythmic.mid",
        "moods": {"strength", "praise", "assurance", "default", "fear"},
    },
    {
        "slug": "all-hail-the-power", "title": "All Hail the Power of Jesus' Name",
        "author": "Edward Perronet", "year": 1779, "scripture": "Philippians 2:9",
        "midi": "All_Hail_The_Power_Of_Jesus_Name-Coronation.mid",
        "moods": {"praise", "joyful", "worship", "reverence"},
    },
    {
        "slug": "holy-god-we-praise", "title": "Holy God, We Praise Thy Name",
        "author": "Ignaz Franz", "year": 1774, "scripture": "Revelation 4:8",
        "midi": "Holy_God_We_Praise_Thy_Name-Te_Deum-Hursley.mid",
        "moods": {"praise", "reverence", "worship", "default"},
    },
    {
        "slug": "o-for-a-thousand-tongues", "title": "O for a Thousand Tongues to Sing",
        "author": "Charles Wesley", "year": 1739, "scripture": "Psalm 66:8",
        "midi": "O_For_A_Thousand_Tongues-Azmon.mid",
        "moods": {"praise", "joyful", "grateful", "default"},
    },
    {
        "slug": "praise-god-doxology", "title": "Praise God, from Whom All Blessings Flow",
        "author": "Thomas Ken", "year": 1674, "scripture": "Psalm 100:1",
        "midi": "Praise_God_From_Whom_All_Blessings_Flow-Old_100th.mid",
        "moods": {"praise", "grateful", "default", "worship"},
    },
    {
        "slug": "praise-my-soul", "title": "Praise, My Soul, the King of Heaven",
        "author": "Henry F. Lyte", "year": 1834, "scripture": "Psalm 103:1",
        "midi": "Praise_My_Soul_The_King_Of_Heaven-Praise_My_Soul-Lauda_Anima.mid",
        "moods": {"praise", "grateful", "joyful", "worship"},
    },
    {
        "slug": "immortal-invisible", "title": "Immortal, Invisible, God Only Wise",
        "author": "Walter Chalmers Smith", "year": 1867, "scripture": "1 Timothy 1:17",
        "midi": "Immortal_Invisible_God_Only_Wise-St_Denio.mid",
        "moods": {"praise", "reverence", "awe", "worship"},
    },
    {
        "slug": "i-sing-mighty-power", "title": "I Sing the Mighty Power of God",
        "author": "Isaac Watts", "year": 1715, "scripture": "Psalm 19:1",
        "midi": "I_Sing_The_Mighty_Power_Of_God-Ellacombe.mid",
        "moods": {"praise", "joyful", "creation", "grateful"},
    },
    {
        "slug": "beautiful-savior", "title": "Beautiful Savior (Fairest Lord Jesus)",
        "author": "Münster Gesangbuch", "year": 1677, "scripture": "Song of Solomon 2:1",
        "midi": "Beautiful_Savior-Crusaders_Hymn.mid",
        "moods": {"praise", "devotion", "reverence", "joyful", "default"},
    },
    {
        "slug": "for-the-beauty", "title": "For the Beauty of the Earth",
        "author": "Folliott S. Pierpoint", "year": 1864, "scripture": "Psalm 19:1",
        "midi": "For_The_Beauty_Of_The_Earth-Dix.mid",
        "moods": {"grateful", "joyful", "creation", "praise"},
    },
    {
        "slug": "our-god-our-help", "title": "Our God, Our Help in Ages Past",
        "author": "Isaac Watts", "year": 1719, "scripture": "Psalm 90:1",
        "midi": "Our_God_Our_Help_In_Ages_Past-St_Anne.mid",
        "moods": {"assurance", "strength", "trust", "default"},
    },
    {
        "slug": "we-gather-together", "title": "We Gather Together",
        "author": "Adrianus Valerius", "year": 1597, "scripture": "1 Thessalonians 5:18",
        "midi": "We_Gather_Together-Kremser.mid",
        "moods": {"thanksgiving", "community", "praise", "grateful"},
    },
    {
        "slug": "jesus-shall-reign", "title": "Jesus Shall Reign Where'er the Sun",
        "author": "Isaac Watts", "year": 1719, "scripture": "Psalm 72:8",
        "midi": "Jesus_Shall_Reign-Duke_Street.mid",
        "moods": {"praise", "kingdom", "mission", "hope", "joyful"},
    },
    {
        "slug": "and-can-it-be", "title": "And Can It Be That I Should Gain",
        "author": "Charles Wesley", "year": 1738, "scripture": "Romans 5:8",
        "midi": "And_Can_It_Be-Fillmore.mid",
        "moods": {"grateful", "grace", "joyful", "assurance", "wonder"},
    },
    {
        "slug": "o-day-of-rest", "title": "O Day of Rest and Gladness",
        "author": "Christopher Wordsworth", "year": 1862, "scripture": "Genesis 2:3",
        "midi": "O_Day_Of_Rest_And_Gladness-Woodbird.mid",
        "moods": {"worship", "rest", "sabbath", "grateful", "default"},
    },

    # === COMFORT & TRUST ===
    {
        "slug": "god-will-take-care", "title": "God Will Take Care of You",
        "author": "Civilla D. Martin", "year": 1904, "scripture": "Philippians 4:19",
        "midi": "God_Will_Take_Care_Of_You-Martin.mid",
        "moods": {"comfort", "trust", "anxious", "hope", "assurance"},
    },
    {
        "slug": "under-his-wings", "title": "Under His Wings",
        "author": "William O. Cushing", "year": 1896, "scripture": "Psalm 91:4",
        "midi": "Under_His_Wings-Under_His_Wings.mid",
        "moods": {"comfort", "trust", "refuge", "anxious", "peace"},
    },
    {
        "slug": "he-leadeth-me", "title": "He Leadeth Me, O Blessed Thought",
        "author": "Joseph H. Gilmore", "year": 1862, "scripture": "Psalm 23:3",
        "midi": "He_Leadeth_Me-He_Leadeth_Me.mid",
        "moods": {"trust", "guidance", "comfort", "default", "peace"},
    },
    {
        "slug": "the-lords-my-shepherd", "title": "The Lord's My Shepherd (Psalm 23)",
        "author": "Scottish Psalter", "year": 1650, "scripture": "Psalm 23:1",
        "midi": "The_Lords_My_Shepherd-Marosa-Brother_James_Air.mid",
        "moods": {"comfort", "trust", "peace", "default", "guidance"},
    },
    {
        "slug": "savior-like-a-shepherd", "title": "Savior, Like a Shepherd Lead Us",
        "author": "Dorothy Thrupp", "year": 1836, "scripture": "Psalm 23:1",
        "midi": "Savior_Like_A_Shepherd_Lead_Us-Bradbury.mid",
        "moods": {"comfort", "trust", "guidance", "seeking"},
    },
    {
        "slug": "no-not-one", "title": "No, Not One",
        "author": "Johnson Oatman Jr.", "year": 1895, "scripture": "John 15:15",
        "midi": "No_Not_One-No_Not_One.mid",
        "moods": {"comfort", "friendship", "trust", "lonely"},
    },
    {
        "slug": "the-love-of-god", "title": "The Love of God",
        "author": "Frederick M. Lehman", "year": 1917, "scripture": "Romans 8:38",
        "midi": "The_Love_Of_God-The_Love_Of_God.mid",
        "moods": {"comfort", "love", "hope", "grateful", "grace"},
    },
    {
        "slug": "moment-by-moment", "title": "Moment by Moment",
        "author": "Daniel Whittle", "year": 1893, "scripture": "John 15:5",
        "midi": "Moment_By_Moment-Whittle.mid",
        "moods": {"trust", "peace", "assurance", "devotion"},
    },
    {
        "slug": "he-keeps-me-singing", "title": "He Keeps Me Singing",
        "author": "Luther B. Bridgers", "year": 1910, "scripture": "Zephaniah 3:17",
        "midi": "He_Keeps_Me_Singing-He_Keeps_Me_Singing-Melody_of_Love.mid",
        "moods": {"joyful", "comfort", "peace", "trust", "happy"},
    },
    {
        "slug": "i-know-whom-believed", "title": "I Know Whom I Have Believed",
        "author": "Daniel Whittle", "year": 1883, "scripture": "2 Timothy 1:12",
        "midi": "I_Know_Whom_I_Have_Believed-I_Know_Whom_I_Have_Believed.mid",
        "moods": {"assurance", "faith", "hope", "trust"},
    },
    {
        "slug": "jerusalem-the-golden", "title": "Jerusalem the Golden",
        "author": "Bernard of Cluny", "year": 1145, "scripture": "Revelation 21:18",
        "midi": "Jerusalem_the_Golden-Ewing.mid",
        "moods": {"heaven", "hope", "longing", "comfort"},
    },
    {
        "slug": "jesus-loves-me", "title": "Jesus Loves Me",
        "author": "Anna B. Warner", "year": 1860, "scripture": "John 3:16",
        "midi": "Jesus_Loves_Me-untitled.mid",
        "moods": {"comfort", "love", "hope", "default"},
    },
    {
        "slug": "jesus-is-all-the-world", "title": "Jesus Is All the World to Me",
        "author": "Will L. Thompson", "year": 1904, "scripture": "Philippians 1:21",
        "midi": "Jesus_Is_All_The_World_to_Me-Jesus_Is_All_The_World_to_Me.mid",
        "moods": {"devotion", "love", "trust", "grateful"},
    },
    {
        "slug": "my-saviors-love", "title": "My Savior's Love (I Stand Amazed)",
        "author": "Charles H. Gabriel", "year": 1905, "scripture": "Romans 5:8",
        "midi": "My_Saviors_Love-My_Saviors_Love-I_Stand_Amazed_in_the_Presence.mid",
        "moods": {"grateful", "wonder", "love", "joyful", "grace"},
    },

    # === SEEKING, PRAYER & DEVOTION ===
    {
        "slug": "more-love-to-thee", "title": "More Love to Thee, O Christ",
        "author": "Elizabeth P. Prentiss", "year": 1856, "scripture": "Philippians 1:9",
        "midi": "More_Love_To_Thee-More_Love_To_Thee.mid",
        "moods": {"seeking", "prayer", "devotion", "longing"},
    },
    {
        "slug": "where-he-leads-me", "title": "Where He Leads Me",
        "author": "E. W. Blandy", "year": 1890, "scripture": "John 12:26",
        "midi": "Where_He_Leads_Me-Where_He_Leads_Me.mid",
        "moods": {"seeking", "devotion", "trust", "dedication", "journey"},
    },
    {
        "slug": "take-my-life", "title": "Take My Life and Let It Be",
        "author": "Frances R. Havergal", "year": 1874, "scripture": "Romans 12:1",
        "midi": "Take_My_Life_And_Let_It_Be-Mozart.mid",
        "moods": {"devotion", "dedication", "consecration", "seeking", "surrender"},
    },
    {
        "slug": "my-faith-looks-up", "title": "My Faith Looks Up to Thee",
        "author": "Ray Palmer", "year": 1830, "scripture": "Hebrews 12:2",
        "midi": "My_Faith_Looks_Up_To_Thee-Olivet.mid",
        "moods": {"seeking", "trust", "hope", "prayer", "devotion"},
    },
    {
        "slug": "when-i-survey-cross", "title": "When I Survey the Wondrous Cross",
        "author": "Isaac Watts", "year": 1707, "scripture": "Galatians 6:14",
        "midi": "When_I_Survey_The_Wondrous_Cross-Hamburg.mid",
        "moods": {"devotion", "reverence", "grateful", "cross", "contemplation"},
    },
    {
        "slug": "o-sacred-head", "title": "O Sacred Head, Now Wounded",
        "author": "Bernard of Clairvaux", "year": 1153, "scripture": "Isaiah 53:5",
        "midi": "O_Sacred_Head_Now_Wounded-Passion_Chorale-Herzlich_Tut_Mich_Verlangen.mid",
        "moods": {"reverence", "cross", "sorrow", "grateful", "passion", "grieving"},
    },
    {
        "slug": "beneath-the-cross", "title": "Beneath the Cross of Jesus",
        "author": "Elizabeth C. Clephane", "year": 1868, "scripture": "John 19:25",
        "midi": "Beneath_The_Cross_Of_Jesus-St_Christopher.mid",
        "moods": {"cross", "seeking", "refuge", "devotion", "grieving"},
    },
    {
        "slug": "hallelujah-what-a-savior", "title": "Hallelujah! What a Savior",
        "author": "Philip P. Bliss", "year": 1875, "scripture": "Isaiah 53:3",
        "midi": "Hallelujah_What_a_Savior-Hallelujah_What_a_Savior.mid",
        "moods": {"grateful", "cross", "praise", "hope", "grace"},
    },
    {
        "slug": "lord-jesus-think-on-me", "title": "Lord Jesus, Think on Me",
        "author": "Synesius of Cyrene", "year": 430, "scripture": "Luke 23:42",
        "midi": "Lord_Jesus_Think_On_Me-Southwell.mid",
        "moods": {"seeking", "prayer", "penitence", "comfort", "grieving"},
    },
    {
        "slug": "were-you-there", "title": "Were You There (When They Crucified My Lord)",
        "author": "African American Spiritual", "year": 1865, "scripture": "Luke 23:33",
        "midi": "Were_You_There-Were_You_There.mid",
        "moods": {"reverence", "cross", "sorrow", "devotion", "contemplation"},
    },
    {
        "slug": "whiter-than-snow", "title": "Whiter Than Snow",
        "author": "James Nicholson", "year": 1872, "scripture": "Psalm 51:7",
        "midi": "Whiter_Than_Snow-Whiter_Than_Snow.mid",
        "moods": {"seeking", "cleansing", "forgiveness", "prayer", "penitence"},
    },
    {
        "slug": "did-you-think-to-pray", "title": "Did You Think to Pray?",
        "author": "Mary A. Pepper Kidder", "year": 1876, "scripture": "1 Thessalonians 5:17",
        "midi": "Did_You_Think_To_Pray-Stockbridge.mid",
        "moods": {"prayer", "seeking", "devotion"},
    },
    {
        "slug": "let-all-mortal-flesh", "title": "Let All Mortal Flesh Keep Silence",
        "author": "Liturgy of St. James", "year": 350, "scripture": "Habakkuk 2:20",
        "midi": "Let_All_Mortal_Flesh_Keep_Silence-Picardy.mid",
        "moods": {"reverence", "worship", "awe", "contemplation"},
    },

    # === FAITH & ASSURANCE ===
    {
        "slug": "faith-of-our-fathers", "title": "Faith of Our Fathers",
        "author": "Frederick W. Faber", "year": 1849, "scripture": "Hebrews 11:1",
        "midi": "Faith_Of_Our_Fathers-St_Catherine.mid",
        "moods": {"faith", "assurance", "dedication", "strength"},
    },
    {
        "slug": "the-old-rugged-cross", "title": "The Old Rugged Cross",
        "author": "George Bennard", "year": 1913, "scripture": "1 Corinthians 1:18",
        "midi": "The_Old_Rugged_Cross-Old_Rugged_Cross.mid",
        "moods": {"cross", "grateful", "devotion", "love", "hope"},
    },
    {
        "slug": "when-roll-is-called", "title": "When the Roll Is Called Up Yonder",
        "author": "James M. Black", "year": 1893, "scripture": "Revelation 20:12",
        "midi": "When_the_Roll_is_Called_Up_Yonder-When_the_Roll_is_Called_Up_Yonder.mid",
        "moods": {"hope", "heaven", "joyful", "resurrection"},
    },
    {
        "slug": "theres-a-great-day", "title": "There's a Great Day Coming",
        "author": "Will L. Thompson", "year": 1880, "scripture": "Matthew 25:31",
        "midi": "Theres_A_Great_Day_Coming-Theres_A_Great_Day_Coming.mid",
        "moods": {"hope", "heaven", "coming"},
    },
    {
        "slug": "built-on-the-rock", "title": "Built on the Rock the Church Doth Stand",
        "author": "Nicolai F. S. Grundtvig", "year": 1837, "scripture": "Matthew 16:18",
        "midi": "Built_On_The_Rock-Kirken_Den_Er_Et_Gammelt_Hus.mid",
        "moods": {"church", "assurance", "faith", "strength"},
    },

    # === HOLY SPIRIT ===
    {
        "slug": "holy-ghost-with-light", "title": "Holy Ghost, with Light Divine",
        "author": "Andrew Reed", "year": 1817, "scripture": "John 16:13",
        "midi": "Holy_Ghost_With_Light_Divine-Canterbury-Song_13.mid",
        "moods": {"holy spirit", "peace", "comfort", "seeking"},
    },
    {
        "slug": "come-down-o-love-divine", "title": "Come Down, O Love Divine",
        "author": "Bianco da Siena", "year": 1367, "scripture": "John 14:16",
        "midi": "Come_Down_O_Love_Divine-Down_Ampney.mid",
        "moods": {"holy spirit", "devotion", "seeking", "peace"},
    },
    {
        "slug": "come-holy-spirit", "title": "Come, Holy Spirit, Lord Our God",
        "author": "German hymn", "year": 1524, "scripture": "Acts 2:4",
        "midi": "Come_Holy_Spirit_Lord_Our_God-Komm_Heiliger_Geist_Herre_Gott.mid",
        "moods": {"holy spirit", "seeking", "pentecost", "worship"},
    },
    {
        "slug": "holy-spirit-ever-dwelling", "title": "Holy Spirit, Ever Dwelling",
        "author": "Timothy Rees", "year": 1922, "scripture": "Romans 8:26",
        "midi": "Holy_Spirit_Ever_Dwelling-Ebenezer-Ton_Y_Botel.mid",
        "moods": {"holy spirit", "comfort", "guidance", "peace"},
    },
    {
        "slug": "the-comforter-has-come", "title": "The Comforter Has Come",
        "author": "Frank Bottome", "year": 1890, "scripture": "John 14:26",
        "midi": "The_Comforter_Has_Come-The_Comforter_Has_Come.mid",
        "moods": {"holy spirit", "joyful", "comfort", "peace"},
    },

    # === SERVICE & MISSION ===
    {
        "slug": "rescue-the-perishing", "title": "Rescue the Perishing",
        "author": "Fanny J. Crosby", "year": 1869, "scripture": "Luke 14:23",
        "midi": "Rescue_the_Perishing-Rescue_the_Perishing.mid",
        "moods": {"mission", "service", "compassion", "calling"},
    },
    {
        "slug": "lift-high-the-cross", "title": "Lift High the Cross",
        "author": "George W. Kitchin", "year": 1887, "scripture": "Galatians 6:14",
        "midi": "Lift_High_The_Cross-Crucifier.mid",
        "moods": {"mission", "faith", "dedication", "cross", "praise"},
    },
    {
        "slug": "he-was-not-willing", "title": "He Was Not Willing",
        "author": "Lucy R. Meyer", "year": 1894, "scripture": "Matthew 18:14",
        "midi": "He_Was_Not_Willing-He_Was_Not_Willing.mid",
        "moods": {"mission", "compassion", "service", "calling"},
    },
    {
        "slug": "for-all-the-saints", "title": "For All the Saints",
        "author": "William Walsham How", "year": 1864, "scripture": "Hebrews 12:1",
        "midi": "For_All_The_Saints-Sine_Nomine.mid",
        "moods": {"saints", "memorial", "hope", "heaven", "grateful"},
    },
    {
        "slug": "the-churchs-foundation", "title": "The Church's One Foundation",
        "author": "Samuel J. Stone", "year": 1866, "scripture": "1 Corinthians 3:11",
        "midi": "The_Churchs_One_Foundation-Aurelia.mid",
        "moods": {"church", "unity", "faith", "hope", "default"},
    },
    {
        "slug": "blest-be-the-tie", "title": "Blest Be the Tie That Binds",
        "author": "John Fawcett", "year": 1782, "scripture": "Colossians 3:14",
        "midi": "Blest_Be_The_Tie_That_Binds-Boylston.mid",
        "moods": {"fellowship", "community", "love", "unity"},
    },
]

# Public-domain SUNG recordings from the Internet Archive 78rpm collection. Each was
# published in 1925 or earlier, so the SOUND RECORDING is public domain in the US
# (Music Modernization Act); the hymn text/tune is 19th-century or older, so both
# layers are clear. Sourced and verified (right hymn + a singer present + fetchable
# mp3) by source_recordings.py. Hymns NOT listed here have no pre-1925 vocal take —
# in sung mode the selector simply won't consider them; instrumental mode covers all.
RECORDINGS: dict[str, dict] = {
    "it-is-well": {"performer": "Stanley & Gillette", "year": 1914,
        "url": "https://archive.org/download/78_it-is-well-with-my-soul_stanley-gillette-bliss_gbia3029098b/IT%20IS%20WELL%20WITH%20MY%20SOUL%20-%20STANLEY%20%26%20GILLETTE.mp3"},
    "abide-with-me": {"performer": "Phillip Ritte & James Saker", "year": 1910,
        "url": "https://archive.org/download/78_abide-with-me_messrs-phillip-ritte-and-james-saker-monk_gbia3038628a/ABIDE%20WITH%20ME%20-%20Messrs.%20PHILLIP%20RITTE%20and%20JAMES%20SAKER.mp3"},
    "nearer-my-god": {"performer": "Male Quartette", "year": 1908,
        "url": "https://archive.org/download/78_nearer-my-god-to-thee_mason_gbia0482051a/NEARER%2C%20MY%20GOD%2C%20TO%20THEE%20-%20Mason.mp3"},
    "come-ye-disconsolate": {"performer": "Mabel Garrison", "year": 1920,
        "url": "https://archive.org/download/78_come-ye-disconsolate_mabel-garrison-thomas-moore-samuel-webbe_gbia7019206a/Come%2C%20Ye%20Disconsolate%20-%20Mabel%20Garrison%20-%20Thomas%20Moore.mp3"},
    "what-a-friend": {"performer": "Metropolitan Quartet", "year": 1920,
        "url": "https://archive.org/download/78_what-a-friend-we-have-in-jesus_metropolitan-quartet-charles-g-converse_gbia0078158b/What%20A%20Friend%20We%20Have%20In%20Jesus%20-%20Metropolitan%20Quartet.mp3"},
    "how-firm-a-foundation": {"performer": "Metropolitan Quartet", "year": 1925,
        "url": "https://archive.org/download/edison-80858_01_10629/cusb_ed_80858_01_10629_0b.mp3"},
    "now-thank-we": {"performer": "Church Choir", "year": 1925,
        "url": "https://archive.org/download/78_now-thank-we-all-our-god_church-choir-j-crger_gbia3044177b/Now%20thank%20we%20all%20our%20God%20-%20Church%20Choir%20-%20J.%20CR%C3%9CGER.mp3"},
    "come-thou-fount": {"performer": "Metropolitan Quartet", "year": 1921,
        "url": "https://archive.org/download/78_come-thou-fount-of-every-blessing_john-wyeth-metropolitan-quartet_gbia0083652a/Come%2C%20Thou%20Fount%20Of%20Every%20Blessing%20-%20John%20Wyeth.mp3"},
    "rock-of-ages": {"performer": "Irving Gillette", "year": 1910,
        "url": "https://archive.org/download/78_rock-of-ages_irving-gillette-dr-hastings_gbia3029385a/ROCK%20OF%20AGES%20-%20IRVING%20GILLETTE%20-%20Dr.%20Hastings.mp3"},
    "i-need-thee": {"performer": "Alma Gluck & Louise Homer", "year": 1914,
        "url": "https://archive.org/download/78_i-need-thee-every-hour_alma-gluck-louise-homer-annie-s-hawks-robert-lowry_gbia0016538b/I%20Need%20Thee%20Every%20Hour%20-%20Alma%20Gluck%20-%20Louise%20Homer.mp3"},
}

# Attach each hymn's recording (or None) onto its catalog entry.
for _h in HYMNS:
    _h["recording"] = RECORDINGS.get(_h["slug"])

BY_SLUG: dict[str, dict] = {h["slug"]: h for h in HYMNS}


def select(*, mood: str = "", prompt: str = "", query: str = "", eligible: set[str] | None = None) -> dict | None:
    """Pick a hymn whose mood tags best match the worshipper's input.

    `eligible` (optional) restricts the pool to slugs known to be seeded, so we
    never hand back an MP3 that was never rendered.

    The worshipper's chosen `mood` ("Grateful", "Grieving", ...) is the primary
    signal: we match on it first. The LLM-written `prompt`/`query` are only a
    fallback, NOT mixed in — they're verbose worship prose that mentions the same
    generic words ("hope", "grace", "praise", "comfort") for every service, so
    scoring against them pins one hymn per machine regardless of the chosen mood.
    Ties and no-match both resolve by random choice (varying the hymn across
    repeat services); the no-match pool is the "default"-tagged hymns, so this
    always returns one.
    """
    pool = [h for h in HYMNS if eligible is None or h["slug"] in eligible]
    if not pool:
        return None

    def _best(text: str) -> list[dict]:
        text = text.lower()
        scored = [(sum(1 for tag in h["moods"] if tag in text), h) for h in pool]
        best = max(score for score, _ in scored)
        return [h for score, h in scored if score == best] if best > 0 else []

    matches = (
        _best(mood)                          # the worshipper's chosen feeling wins
        or _best(f"{prompt} {query}")        # then the LLM's prose, only if mood missed
        or [h for h in pool if "default" in h["moods"]]
        or pool
    )

    return random.choice(matches)
