<?php

// Nombres de pila frecuentes en Chile (sin tildes, minúsculas). Sirve para decidir el orden "Nombres Apellidos" / "Apellidos Nombres".
return array_flip(preg_split('/\s+/', trim('
maria jose juan luis carlos jorge manuel pedro miguel francisco antonio cristian christian cristobal claudio sergio patricio ricardo roberto eduardo fernando
rodrigo pablo felipe andres diego alejandro javier daniel david gabriel ignacio sebastian matias nicolas benjamin vicente martin tomas joaquin agustin
mauricio marcelo marco marcos mario hector hugo oscar victor raul ramon rafael rene renato rolando rogelio ruben samuel santiago sandro segundo simon
guillermo gonzalo german gerardo gustavo hernan heriberto humberto ivan jaime jairo jesus joel julio julian leonardo leonel lorenzo luciano lucas
alberto alfredo alonso alvaro angel armando arturo augusto aurelio bernardo bruno camilo cesar clemente danilo dario domingo edgardo edgar elias emilio
enrique ernesto esteban eugenio evaristo exequiel fabian facundo fidel flavio florencio franco froilan gaston genaro gilberto herman inaki isaac isaias
ismael ivo jacinto jeremias jonathan josue kevin leandro lautaro lisandro mathias maximiliano maximo moises nelson nestor nibaldo noe omar orlando osvaldo
otto pascual paulo plinio prudencio quintin reinaldo reynaldo robinson rodolfo rosendo salvador saul silvio tadeo teodoro thomas timoteo ulises valentin
vladimir walter wilson william xavier yerko yeferson yonathan zacarias
ana maria carmen rosa patricia claudia carolina andrea paola marcela alejandra daniela constanza camila valentina catalina francisca fernanda javiera
isabel isidora antonia josefa trinidad florencia sofia emilia amanda martina renata agustina macarena paulina pamela karen karina natalia nataly veronica
viviana soledad sandra silvia susana teresa marta margarita mercedes monica lorena loreto luz luisa liliana laura lucia lidia leticia jessica jacqueline
ingrid ines irma ivonne gloria gladys graciela gabriela fabiola eliana elena elisa elizabeth eugenia ester eva evelyn erika ema dina cecilia celia
cristina clara carla beatriz barbara berta blanca bernardita angelica angela alicia alba adriana ximena yasna yessica yolanda zoila ursula rocio romina
rebeca raquel sara sabina priscila olga norma nora noemi nancy mirta miriam mireya milagros michelle melissa maribel marisol marlene marianela magdalena
lucila lourdes lilian leonor jenny janet johanna jimena josefina julia juana juliana hilda herminia haydee fresia flor filomena felicia estela esperanza
elsa eloisa edith dorotea dolores delia denise damaris cynthia corina consuelo constanza concepcion carola candelaria brenda belen bastian aurora
anahi alexandra abigail aida
angeles pilar cruz rosario soledad dolores remedios nieves paz amparo asuncion encarnacion socorro lourdes guadalupe milagro
')));
