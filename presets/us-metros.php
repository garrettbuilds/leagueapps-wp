<?php
/**
 * Suburbs to metros, for the cities LGBTQ+ softball actually travels between.
 *
 * A STARTING POINT, NOT A GAZETTEER. The scope is deliberately narrow: the
 * member cities of International Pride Softball and ASANA, plus the suburbs that
 * turned up in real registration data. That is a short, knowable list, which is
 * why a lookup works here at all.
 *
 * ONE NAME, THE ONE PEOPLE SAY. Dallas, not Dallas-Fort Worth. Miami, not
 * Miami-Fort Lauderdale. A reader scanning a team list is placing the team on a
 * mental map, not reading a census boundary.
 *
 * Anywhere unlisted falls through as written, so expect to add entries when an
 * unfamiliar town appears. That fallback is also the more identifying one: an
 * unmatched suburb is published as the suburb.
 */

return array(
	'Austin'             => array( 'Austin', 'Round Rock', 'Cedar Park', 'Pflugerville', 'Georgetown', 'Kyle', 'Buda', 'Leander', 'San Marcos', 'Manor', 'Del Valle' ),
	'Dallas'  => array( 'Dallas', 'Fort Worth', 'Frisco', 'Plano', 'Irving', 'Arlington', 'Denton', 'McKinney', 'Garland', 'Richardson', 'Carrollton', 'Grapevine', 'Lewisville', 'Allen', 'Mesquite' ),
	'Houston'            => array( 'Houston', 'Katy', 'Sugar Land', 'Pearland', 'The Woodlands', 'Spring', 'Humble', 'Pasadena', 'Cypress', 'League City' ),
	'San Antonio'        => array( 'San Antonio', 'New Braunfels', 'Schertz', 'Converse' ),
	'Oklahoma City'      => array( 'Oklahoma City', 'Norman', 'Edmond', 'Moore', 'Yukon' ),
	'Tulsa'              => array( 'Tulsa', 'Broken Arrow', 'Owasso' ),
	'St. Louis'          => array( 'St Louis', 'Chesterfield', 'Kirkwood', 'Florissant', 'OFallon', 'Ballwin', 'Maryland Heights', 'Webster Groves' ),
	'Kansas City'        => array( 'Kansas City', 'Overland Park', 'Olathe', 'Lees Summit', 'Independence', 'Shawnee' ),
	'Minneapolis'        => array( 'Minneapolis', 'St Paul', 'Bloomington', 'Edina', 'Eagan', 'Plymouth', 'Maple Grove', 'Burnsville', 'Richfield' ),
	'Chicago'            => array( 'Chicago', 'Evanston', 'Oak Park', 'Naperville', 'Schaumburg', 'Skokie', 'Berwyn', 'Cicero' ),
	'Denver'             => array( 'Denver', 'Aurora', 'Lakewood', 'Boulder', 'Littleton', 'Arvada', 'Westminster', 'Englewood' ),
	'Phoenix'            => array( 'Phoenix', 'Tempe', 'Mesa', 'Scottsdale', 'Chandler', 'Glendale', 'Gilbert', 'Peoria' ),
	'Tucson'             => array( 'Tucson', 'Marana', 'Oro Valley' ),
	'Las Vegas'          => array( 'Las Vegas', 'Henderson', 'North Las Vegas', 'Paradise' ),
	'Salt Lake City'     => array( 'Salt Lake City', 'West Valley City', 'Sandy', 'Provo', 'Ogden' ),
	'Albuquerque'        => array( 'Albuquerque', 'Rio Rancho', 'Santa Fe' ),
	'Los Angeles'        => array( 'Los Angeles', 'Long Beach', 'Pasadena', 'Burbank', 'Glendale', 'Santa Monica', 'West Hollywood', 'Anaheim', 'Irvine', 'Santa Ana', 'Torrance' ),
	'San Diego'          => array( 'San Diego', 'Chula Vista', 'Oceanside', 'Escondido', 'La Mesa' ),
	'San Francisco'  => array( 'San Francisco', 'Oakland', 'Berkeley', 'San Jose', 'Palo Alto', 'Fremont', 'Hayward', 'Sunnyvale', 'Santa Clara', 'Daly City' ),
	'Sacramento'         => array( 'Sacramento', 'Elk Grove', 'Roseville', 'Folsom', 'Davis' ),
	'Seattle'            => array( 'Seattle', 'Bellevue', 'Tacoma', 'Kirkland', 'Redmond', 'Everett', 'Renton' ),
	'Portland'           => array( 'Portland', 'Beaverton', 'Hillsboro', 'Gresham', 'Vancouver' ),
	'Atlanta'            => array( 'Atlanta', 'Decatur', 'Marietta', 'Alpharetta', 'Sandy Springs', 'Roswell', 'Smyrna' ),
	'Nashville'          => array( 'Nashville', 'Franklin', 'Murfreesboro', 'Brentwood' ),
	'New Orleans'        => array( 'New Orleans', 'Metairie', 'Kenner' ),
	'Miami' => array( 'Miami', 'Fort Lauderdale', 'Hollywood', 'Miami Beach', 'Hialeah', 'Coral Gables', 'Wilton Manors', 'Pembroke Pines' ),
	'Tampa'              => array( 'Tampa', 'St Petersburg', 'Clearwater', 'Brandon' ),
	'Orlando'            => array( 'Orlando', 'Kissimmee', 'Winter Park', 'Altamonte Springs' ),
	'Charlotte'          => array( 'Charlotte', 'Concord', 'Gastonia', 'Huntersville' ),
	'Raleigh'     => array( 'Raleigh', 'Durham', 'Chapel Hill', 'Cary', 'Apex' ),
	'Washington'      => array( 'Washington', 'Arlington', 'Alexandria', 'Bethesda', 'Silver Spring', 'Rockville', 'Fairfax', 'Falls Church' ),
	'Baltimore'          => array( 'Baltimore', 'Towson', 'Columbia', 'Ellicott City' ),
	'Philadelphia'       => array( 'Philadelphia', 'Camden', 'Cherry Hill', 'King of Prussia', 'Upper Darby' ),
	'New York'           => array( 'New York', 'Brooklyn', 'Queens', 'Bronx', 'Staten Island', 'Jersey City', 'Hoboken', 'Newark', 'Yonkers', 'Long Island City' ),
	'Boston'             => array( 'Boston', 'Cambridge', 'Somerville', 'Brookline', 'Quincy', 'Medford', 'Newton' ),
	'Providence'         => array( 'Providence', 'Pawtucket', 'Cranston' ),
	'Hartford'           => array( 'Hartford', 'West Hartford', 'New Britain' ),
	'Pittsburgh'         => array( 'Pittsburgh', 'Bethel Park', 'Monroeville' ),
	'Cleveland'          => array( 'Cleveland', 'Lakewood', 'Parma', 'Euclid' ),
	'Columbus'           => array( 'Columbus', 'Dublin', 'Westerville', 'Gahanna', 'Grove City' ),
	'Cincinnati'         => array( 'Cincinnati', 'Covington', 'Newport', 'Hamilton' ),
	'Detroit'            => array( 'Detroit', 'Ferndale', 'Royal Oak', 'Ann Arbor', 'Dearborn', 'Warren' ),
	'Milwaukee'          => array( 'Milwaukee', 'Wauwatosa', 'West Allis', 'Waukesha' ),
	'Indianapolis'       => array( 'Indianapolis', 'Carmel', 'Fishers', 'Greenwood' ),
	'Louisville'         => array( 'Louisville', 'Jeffersontown' ),
	'Memphis'            => array( 'Memphis', 'Germantown', 'Bartlett' ),
	'Richmond'           => array( 'Richmond', 'Henrico', 'Chesterfield County' ),
	'Buffalo'            => array( 'Buffalo', 'Amherst', 'Cheektowaga' ),
);
