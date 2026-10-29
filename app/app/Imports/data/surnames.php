<?php

// Apellidos frecuentes en Chile (sin tildes, minúsculas).
return array_flip(preg_split('/\s+/', trim('
gonzalez munoz rojas diaz perez soto contreras silva martinez sepulveda morales rodriguez lopez araya fuentes hernandez torres espinoza flores castillo
valenzuela tapia reyes gutierrez castro pizarro alvarez vasquez fernandez sanchez carrasco gomez cortes herrera nunez jara vergara rivera figueroa
riquelme garcia miranda bravo vera molina vega campos sandoval orellana cardenas olivares alarcon gallardo ortiz garrido salazar guzman henriquez
saavedra navarro aguilera parra romero aravena vargas vidal caceres yanez ruiz meza pino pinto ramirez ramos mendoza medina lagos leiva lara godoy
escobar bustos bustamante cabrera cifuentes concha donoso duran espinosa fernandes ferrada fierro gaete galvez gatica godoy guerrero ibarra jimenez
jofre larenas lazo leon lillo maldonado marin mansilla mardones mella melo mendez monsalve montecinos mora moya neira ojeda olmos opazo ortega osorio
oyarzun padilla palma pardo paredes pavez pereira poblete pozo quezada quiroz reyes rios rubio saez salas salinas sanhueza santibanez santos seguel
sierra tello toro troncoso ulloa urbina urrutia valdes valdivia valencia varas velasquez venegas vial villalobos villarroel zamora zapata zuniga
acevedo aguirre agurto alegria almonacid alvarado andrade arancibia arce arenas ariza avendano avila ayala baeza barrera barrios barra becerra benitez
bernal bobadilla bolados briones brito burgos bruna calderon camus cano cartes carvajal casanova catalan cerda chavez cisternas correa cordova cuevas
delgado dominguez echeverria elgueta encina farias fica fonseca gajardo galaz gamboa gil godoy guajardo guerra hidalgo huerta hurtado iturra jeria
labra lizama lobos lucero madrid mancilla matus millan montero montes moreno morgado naranjo nieto novoa oliva oliveros ormeno pacheco pasten pedraza
pena perea pezoa plaza prado puentes quijada quintana quinteros ravanal retamal rebolledo reveco rebolledo roa robles roman rosales saldivia saldana
sazo serrano sobarzo solis tobar toledo tordecilla trigo trujillo tobar vallejos vivanco yevenes zambrano zavala
quispe mamani choque condori huanca tito challapa flores
')));
