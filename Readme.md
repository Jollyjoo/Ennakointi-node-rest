Explanation:
Import Modules: Ensure fs, axios, and path are imported.
Read JSON File: The getData function reads the JSON file and parses its content.
Fetch Data: The fetchData function sends the JSON content to the API endpoint using axios.post.
Main Function: The main function reads the JSON file and fetches data from the API.
Interval: The setInterval function calls the main function at regular intervals (every 1 minute).


# Fetching REST API Data from Tilastokeskus

Tämä opas auttaa sinua hakemaan tietoja Tilastokeskuksen REST API:sta käyttämällä Node.js:ää.

## Prerequisites

- Node.js installed on your machine
- Basic knowledge of JavaScript and Node.js

## Steps

1. **Initialize a new Node.js project**

    ```bash
    mkdir tilastokeskus-api
    cd tilastokeskus-api
    npm init -y
    ```

2. **Install required packages**

    ```bash
    npm install axios
    ```

3. **Create a script to fetch data**

    Create a file named `index.js` and add the following code:

    ```javascript
    const axios = require('axios');

    const fetchData = async () => {
      try {
         const response = await axios.get('https://api.tilastokeskus.fi/v1/data');
         console.log(response.data);
      } catch (error) {
         console.error('Error fetching data:', error);
      }
    };

    fetchData();
    ```

4. **Run the script**

    ```bash
    node index.js
    ```

This script will fetch data from the Tilastokeskus API and log it to the console.

## Additional Resources

- [Tilastokeskus API Documentation](https://www.tilastokeskus.fi/api)
- [Axios Documentation](https://axios-http.com/docs/intro)
