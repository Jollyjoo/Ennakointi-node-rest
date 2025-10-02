// Toimiva versio 1.0
// Ottaa .json tiedostosta tilastokeskuksen muotoiluvaatimukset.
// Lähettää post-metodilla kyselyn stat.
// Ottaa datan vastaan ja parsii kuukaudet ja arvot.
// ajastus esim. minuutin välein




const fs = require('fs').promises;
const axios = require('axios');
const { json } = require('express');
const path = require('path');

// Tilastokeskuksen API endpoint -konkurssitilastolle
const apiUrl = 'https://pxdata.stat.fi:443/PxWeb/api/v1/fi/StatFin/kony/statfin_kony_pxt_13fb.px';
const interval = 600000; // 16,66 tuntia -ajoitus uuden tilaston hakuun (millisekuntia)
const jsonfile = path.join(__dirname, '_konkurssit.json'); //Tiedostoon määritelty STAT muotoilu halutuista tiedoista

// Otetaan JSON-tiedoston sisältö muuttujaan
async function getData(jsonfile) {
  try {
      const data = await fs.readFile(jsonfile, 'utf8');
      console.log('Reading JSON file:', jsonfile);
      return JSON.parse(data);
  } catch (error) {
      console.error('Error reading JSON file:', error);
      throw error;
  }
}       


async function fetchData(newStatData) {
    try {
        // Lähetetään JSON-sisältö post-metodilla tilastokeskukselle
        console.log('Fetching data from:', apiUrl); // Log the URL being fetched
        const response = await axios.post(apiUrl, newStatData, {
            headers: {
                'Content-Type': 'application/json; charset=utf-8'
            },
            timeout: 10000 // Increase timeout to 10 seconds
        });
        console.log('Response status:', response.status); // Log the response status
        if (response.status === 200) { // jos OK
            const data = response.data;
            console.log('Fetched data:', data);
            // muutetaan JSON string-muotoon ja lisätää pari välilyöntiä että pystyy lukemaan
            const dataString = JSON.stringify(data, null, 2);
            const myObj = JSON.parse(dataString);

            // näytetään kaikki tiedot mitkä ei ole nested
            let text = "";
            for (const x in myObj) {
                text += x + ": ";  
                text += myObj[x] + "\n";
            }
            // test it
                    //laitetaan kuukaudet array...
                    var month_array = [];                                          
                          for (const prop in myObj.dimension.Kuukausi.category.label) {
                            month_array.push(prop);                           
                        }                                            
                    //text += month_array + "\n\n";            
                    // näytetään parsitut vuodet/kuukaudet
                    for (const prop in myObj.dimension.Kuukausi.category.label) {
                            text +=  prop + "\n";
                    }
                    text += "\n";


                    // näytetään parsitut arvot
                    for (let i in myObj.value) {
                        //const indexValue = "";// = myObj.dimension.Kuukausi.category.label["2023M01"] + ": ";
                        text +=  month_array[i] + ": "; // tästä muuttujasta kuukaudet
                        text +=  myObj.value[i] + "\n";                                               
                    }
            // test it        

            console.log(text);
        } else {
            console.error(`Failed to fetch data. Status code: ${response.status}`);
        }
    } catch (error) {
        console.error('Error fetching data:', error.message);
    }
}

// Main function to read the JSON file and fetch data
async function main() {
  try {
      const newStatData = await getData(jsonfile);
      await fetchData(newStatData);
  } catch (error) {
      console.error('Error in main function:', error);
  }
}

main();

setInterval(main, interval); // Fetch data at regular intervals
